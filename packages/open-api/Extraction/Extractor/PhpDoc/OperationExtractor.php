<?php

namespace Draw\Component\OpenApi\Extraction\Extractor\PhpDoc;

use Draw\Component\OpenApi\Extraction\ExtractionContextInterface;
use Draw\Component\OpenApi\Extraction\ExtractionImpossibleException;
use Draw\Component\OpenApi\Extraction\Extractor\TypeSchemaExtractor;
use Draw\Component\OpenApi\Extraction\ExtractorInterface;
use Draw\Component\OpenApi\Schema\BodyParameter;
use Draw\Component\OpenApi\Schema\Operation;
use Draw\Component\OpenApi\Schema\Parameter;
use Draw\Component\OpenApi\Schema\Response;
use Draw\Component\OpenApi\Schema\Schema;
use Exception;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Void_;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use RuntimeException;

class OperationExtractor implements ExtractorInterface
{
    private $contextFactory;
    private $docBlockFactory;
    private $exceptionResponseCodes = [];

    public function __construct(
        DocBlockFactoryInterface $docBlockFactory = null
    ) {
        $this->contextFactory = new ContextFactory();
        $this->docBlockFactory = $docBlockFactory ?: DocBlockFactory::createInstance();
    }

    public function canExtract($source, $target, ExtractionContextInterface $extractionContext): bool
    {
        if (!$source instanceof ReflectionMethod) {
            return false;
        }

        if (!$target instanceof Operation) {
            return false;
        }

        return true;
    }

    private function createDocBlock(Reflector $reflector): DocBlock
    {
        return $this->docBlockFactory
            ->create(
                $reflector,
                $this->contextFactory->createFromReflector($reflector)
            );
    }

    /**
     * Extract the requested data.
     *
     * The system is a incrementing extraction system. A extractor can be call before you and you must complete the
     * extraction.
     *
     * @param ReflectionMethod $source
     * @param Operation        $target
     */
    public function extract($source, $target, ExtractionContextInterface $extractionContext): void
    {
        if (!$this->canExtract($source, $target, $extractionContext)) {
            throw new ExtractionImpossibleException();
        }

        try {
            $docBlock = $this->createDocBlock($source);
        } catch (InvalidArgumentException $exception) {
            return;
        }

        if (!$target->summary) {
            $target->summary = $docBlock->getSummary() ?: null;
        }

        if (!$target->description) {
            $target->description = (string) $docBlock->getDescription() ?: null;
        }

        if ($docBlock->getTagsByName('deprecated')) {
            $target->deprecated = true;
        }

        $this->extractResponse($docBlock, $extractionContext, $source, $target);
        $this->extractExceptionResponses($docBlock, $target);
        $this->extractParameters($docBlock, $target, $extractionContext);
    }

    private function getExceptionInformation(Exception $exception)
    {
        foreach ($this->exceptionResponseCodes as $class => $information) {
            if ($exception instanceof $class) {
                return $information;
            }
        }

        return [500, null];
    }

    public function registerExceptionResponseCodes($exceptionClass, $code = 500, $message = null)
    {
        $this->exceptionResponseCodes[$exceptionClass] = [$code, $message];
    }

    private function extractStatusCode(
        $type,
        Response $response,
        ExtractionContextInterface $extractionContext,
        ReflectionMethod $source
    ): int {
        if (\in_array($type, ['void', 'null'])) {
            return 204;
        }

        $response->schema = $responseSchema = new Schema();
        $subContext = $extractionContext->createSubContext();
        $subContext->setParameter('controller-reflection-method', $source);
        $subContext->setParameter('response', $response);
        $extractionContext->getOpenApi()->extract((string) $type, $responseSchema, $subContext);

        return $subContext->getParameter('response-status-code', 200);
    }

    private function extractExceptionResponses(DocBlock $docBlock, Operation $target): void
    {
        foreach ($docBlock->getTagsByName('throws') as $throwTag) {
            /* @var $throwTag DocBlock\Tags\Throws */

            $type = (string) $throwTag->getType();
            $exceptionClass = new ReflectionClass($type);
            /** @var Exception $exception */
            $exception = $exceptionClass->newInstanceWithoutConstructor();
            list($code, $message) = $this->getExceptionInformation($exception);
            $target->responses[$code] = $exceptionResponse = new Response();

            if ((string) $throwTag->getDescription()) {
                $message = (string) $throwTag->getDescription();
            } else {
                if (!$message) {
                    $exceptionClassDocBlock = $this->createDocBlock($exceptionClass);
                    $message = $exceptionClassDocBlock->getSummary();
                }
            }

            $exceptionResponse->description = (string) $message ?: null;
        }
    }

    private function findBodyParameter(Operation $target): ?BodyParameter
    {
        foreach ($target->parameters as $parameter) {
            if ($parameter instanceof BodyParameter) {
                return $parameter;
            }
        }

        return null;
    }

    private function extractParameters(
        DocBlock $docBlock,
        Operation $target,
        ExtractionContextInterface $extractionContext
    ): void {
        $bodyParameter = $this->findBodyParameter($target);

        foreach ($docBlock->getTagsByName('param') as $paramTag) {
            /* @var $paramTag DocBlock\Tags\Param */

            $parameterName = trim($paramTag->getVariableName(), '$');

            /** @var Parameter|null $parameter */
            $parameter = null;
            foreach ($target->parameters as $existingParameter) {
                if ($existingParameter->name == $parameterName) {
                    $parameter = $existingParameter;
                    break;
                }
            }

            if (null !== $parameter) {
                if (!$parameter->description) {
                    $parameter->description = (string) $paramTag->getDescription() ?: null;
                }

                if ($parameter === $bodyParameter) {
                    if (!$bodyParameter->schema) {
                        $bodyParameter->schema = new Schema();
                    }

                    $subContext = $extractionContext->createSubContext();
                    $extractionContext->getOpenApi()->extract(
                        (string) $paramTag->getType(),
                        $bodyParameter->schema,
                        $subContext
                    );
                } elseif (!$parameter->type) {
                    $primitiveType = TypeSchemaExtractor::getPrimitiveType(
                        (string) $paramTag->getType(),
                        $extractionContext
                    );

                    if (!isset($primitiveType['type'])) {
                        throw new RuntimeException(sprintf('No type found for parameter named [%s] for operation id [%s]', $paramTag->getVariableName(), $target->operationId));
                    }

                    $parameter->type = $primitiveType['type'];
                    if (isset($primitiveType['format'])) {
                        $parameter->format = $primitiveType['format'];
                    }
                }
                continue;
            }

            if (null !== $bodyParameter) {
                /* @var BodyParameter $bodyParameter */
                if (isset($bodyParameter->schema->properties[$parameterName])) {
                    $parameter = $bodyParameter->schema->properties[$parameterName];

                    if (!$parameter->description) {
                        $parameter->description = (string) $paramTag->getDescription() ?: null;
                    }

                    if (!$parameter->type) {
                        $subContext = $extractionContext->createSubContext();
                        $extractionContext->getOpenApi()->extract((string) $paramTag->getType(), $parameter,
                            $subContext);
                    }
                }
            }
        }
    }

    private function extractResponse(
        DocBlock $docBlock,
        ExtractionContextInterface $extractionContext,
        ReflectionMethod $source,
        Operation $target
    ): void {
        /** @var Type[] $types */
        $types = [];
        $hasVoid = false;
        $returnTag = null;
        foreach ($docBlock->getTagsByName('return') as $returnTag) {
            /* @var $returnTag DocBlock\Tags\Return_ */
            $type = $returnTag->getType();
            $hasVoid = $hasVoid || $type instanceof Void_;
            if ($type instanceof Compound) {
                $types = array_merge($types, $type->getIterator()->getArrayCopy());
            } else {
                $types[] = $type;
            }
        }

        if ($hasVoid && count($types) > 1) {
            throw new RuntimeException('Operation returning [void] cannot return anything else.');
        }

        if (!$returnTag) {
            return;
        }

        foreach ($types as $type) {
            $response = new Response();
            $response->description = (string) $returnTag->getDescription() ?: null;
            $statusCode = $this->extractStatusCode($type, $response, $extractionContext, $source);

            $target->responses[$statusCode] = $response;
        }
    }
}
