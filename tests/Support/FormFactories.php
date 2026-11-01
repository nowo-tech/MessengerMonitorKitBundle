<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\FormKitBundle\Form\FormTypeMap;
use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\NowoMessengerMonitorKitExtension;
use Nowo\MessengerMonitorKitBundle\Form\HistoryFilterType;
use Nowo\MessengerMonitorKitBundle\Form\MonitorSettingsType;
use Nowo\MessengerMonitorKitBundle\Form\PurgeType;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;

/**
 * Form factory with the bundle form types (FormKit merger with the bundle and filter profiles) and a CSRF extension.
 */
final class FormFactories
{
    public static function merger(): FormOptionsMerger
    {
        $profile = NowoMessengerMonitorKitExtension::formKitProfile();
        unset($profile['alias']);
        $filter = [
            'translation_domain' => 'messages',
            'auto_placeholder'   => true,
            'auto_help'          => true,
            'defaults'           => ['label' => false, 'required' => false, 'attr' => [], 'row_attr' => [], 'help_attr' => []],
            'field_types'        => [],
            'by_form'            => [],
        ];
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []], 'filter' => $filter];

        return new FormOptionsMerger($profiles, NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory());
    }

    public static function factory(): FormFactoryInterface
    {
        $merger = self::merger();
        $types  = [new PurgeType(), new MonitorSettingsType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([...$types, new HistoryFilterType($merger, new FormTypeMap()), new CsrfOnlyType($merger, new FormTypeMap())], []))
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addExtension(new CsrfExtension(new class implements CsrfTokenManagerInterface {
                public function getToken(string $tokenId): CsrfToken
                {
                    return new CsrfToken($tokenId, 'token-' . $tokenId);
                }

                public function refreshToken(string $tokenId): CsrfToken
                {
                    return $this->getToken($tokenId);
                }

                public function removeToken(string $tokenId): ?string
                {
                    return null;
                }

                public function isTokenValid(CsrfToken $token): bool
                {
                    return true;
                }
            }))
            ->getFormFactory();
    }
}
