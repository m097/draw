<?php

namespace Draw\Bundle\UserBundle\Sonata\Controller;

use Draw\Bundle\UserBundle\Security\TwoFactorAuthenticationUserInterface;
use Draw\Bundle\UserBundle\Sonata\Form\Enable2fa;
use Draw\Bundle\UserBundle\Sonata\Form\Enable2faForm;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\QrCode\QrCodeGenerator;
use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\EventListener\ConfigureCRUDControllerListener;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorAuthenticationController extends CRUDController
{
    public function __construct(ContainerInterface $container)
    {
        if (class_exists(ConfigureCRUDControllerListener::class)) {
            return;
        }
        $this->setContainer($container);

        $this->configureAdmin($container->get('request_stack')->getMasterRequest());
    }

    public function enable2faAction(
        Request $request,
        TotpAuthenticatorInterface $totpAuthenticator,
        QrCodeGenerator $qrCodeGenerator
    ): Response {
        /** @var TwoFactorAuthenticationUserInterface $user */
        $user = $this->admin->getSubject();

        $this->admin->checkAccess('enable-2fa', $user);

        $form = $this->createForm(Enable2faForm::class, $enable2fa = new Enable2fa());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setTotpSecret($enable2fa->totpSecret);
            if ($totpAuthenticator->checkCode($user, $enable2fa->code)) {
                $this->admin->getModelManager()->update($user);

                $this->addFlash(
                    'sonata_flash_success',
                    $this->trans('admin.flash.2fa_enabled', [], 'DrawUserBundle')
                );

                // TODO drop when stop supporting sonata 3.x
                if ((new \ReflectionObject($this))->getMethod('redirectTo')->getNumberOfParameters() > 1) {
                    return $this->redirectTo($request, $user);
                }

                return $this->redirectTo($user);
            }

            $this->addFlash(
                'sonata_flash_error',
                $this->trans('admin.flash.2fa_invalid_code', [], 'DrawUserBundle')
            );
        }

        // If the form was submit it will assigned, we want to keep the same
        // Otherwise we will generate one
        if (!$form->isSubmitted()) {
            $user->setTotpSecret($totpAuthenticator->generateSecret());
            $totpSecret = $user->getTotpSecret();
            $enable2fa->totpSecret = $totpSecret;
            $form->setData($enable2fa);
        }

        $qrCode = $qrCodeGenerator->getTotpQrCode($user);

        return new Response(
            $this->renderWithExtraParams(
                '@DrawUser/Sonata/User/enable_2fa.html.twig',
                [
                    'object' => $user,
                    'action' => 'enable-2fa',
                    'form' => $form->createView(),
                    'qrCodeSvg' => $qrCode->getWriter('svg')->writeString($qrCode),
                    'totpSecret' => $user->getTotpSecret(),
                ]
            )
        );
    }

    public function disable2faAction(Request $request): RedirectResponse
    {
        /** @var TwoFactorAuthenticationUserInterface $user */
        $user = $this->admin->getSubject();

        $this->admin->checkAccess('disable-2fa', $user);

        $user->setTotpSecret(null);
        $this->admin->getModelManager()->update($user);
        $this->addFlash(
            'sonata_flash_success',
            $this->trans('admin.flash.2fa_disabled', [], 'DrawUserBundle')
        );

        // TODO drop when stop supporting sonata 3.x
        if ((new \ReflectionObject($this))->getMethod('redirectTo')->getNumberOfParameters() > 1) {
            return $this->redirectTo($request, $user);
        }

        return $this->redirectTo($user);
    }
}
