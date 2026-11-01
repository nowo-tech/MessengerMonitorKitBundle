<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Manual "Purge history" form (POST + CSRF): older-than period and "keep failed messages".
 *
 * @extends AbstractType<array{older_than: PurgePeriod, keep_failed: bool}>
 */
#[FormKitConfig(NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE)]
final class PurgeType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id. */
    public const CSRF_TOKEN_ID = 'nowo_messenger_monitor_kit_purge';

    /**
     * Fields: older_than (period), keep_failed.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'older_than', EnumType::class, [
            'class'        => PurgePeriod::class,
            'label'        => 'purge.older_than',
            'help'         => 'purge.older_than_help',
            'choice_label' => static fn (PurgePeriod $period): string => $period->translationKey(),
            'data'         => PurgePeriod::OneMonth,
        ]);
        $this->addWithDefaults($builder, 'keep_failed', CheckboxType::class, [
            'label'    => 'purge.keep_failed',
            'help'     => 'purge.keep_failed_help',
            'required' => false,
            'data'     => true,
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
     * `nowo_mmk_purge`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_mmk_purge';
    }
}
