<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Runtime switches form (POST + CSRF): recording, optional recording, automatic purge, retention, keep failed. Data
 * is {@see MonitorSettings::toArray()}.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE)]
final class MonitorSettingsType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id. */
    public const CSRF_TOKEN_ID = 'nowo_messenger_monitor_kit_settings';

    /**
     * Fields in the order of the settings array.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $retention = [];
        foreach (PurgePeriod::retentionCases() as $period) {
            $retention[$period->translationKey()] = $period->value;
        }

        foreach (['recording', 'record_optional', 'auto_purge'] as $switch) {
            $this->addWithDefaults($builder, $switch, CheckboxType::class, [
                'label'    => 'settings.' . $switch,
                'help'     => 'settings.' . $switch . '_help',
                'required' => false,
            ]);
        }
        $this->addWithDefaults($builder, 'retention', ChoiceType::class, [
            'label'   => 'settings.retention',
            'help'    => 'settings.retention_help',
            'choices' => $retention,
        ]);
        $this->addWithDefaults($builder, 'keep_failed', CheckboxType::class, [
            'label'    => 'settings.keep_failed',
            'help'     => 'settings.keep_failed_help',
            'required' => false,
        ]);
    }

    /**
     * POST with CSRF, bundle translation domain.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * `nowo_mmk_settings`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_mmk_settings';
    }
}
