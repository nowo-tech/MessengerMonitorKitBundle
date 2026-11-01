<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Form;

use Nowo\FormKitBundle\Form\AbstractGetFilterType;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Configuration;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Override;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;

use function array_combine;
use function array_map;

/**
 * History list filters (FormKit `filter` profile via {@see AbstractGetFilterType}: GET, no CSRF, rootless names so
 * the query string stays `?period=&status=&transport=&type=&per_page=`, stable for bookmarks).
 */
final class HistoryFilterType extends AbstractGetFilterType
{
    /** Query parameters the history list reads. */
    public const FIELDS = ['period', 'status', 'transport', 'type', 'per_page'];

    /**
     * Fields: period, status, transport, message type, page size.
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $transports */
        $transports = $options['transport_choices'];
        /** @var array<string, string> $types */
        $types  = $options['type_choices'];
        $domain = NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN;

        $this->withBuilder($builder, function () use ($transports, $types, $domain): void {
            $periods = [];
            foreach ([...Period::inLastCases(), ...Period::absoluteCases()] as $period) {
                $periods['history.period.' . $period->value] = $period->value;
            }
            $this->addFilterSelect('period', [
                'label'                     => 'history.filter.period',
                'help'                      => false,
                'choices'                   => $periods,
                'placeholder'               => false,
                'required'                  => true,
                'translation_domain'        => $domain,
                'choice_translation_domain' => $domain,
            ]);
            $this->addFilterSelect('status', [
                'label'                     => 'history.filter.status',
                'help'                      => false,
                'choices'                   => ['status.success' => Specification::SUCCESS, 'status.failed' => Specification::FAILED],
                'placeholder'               => 'history.filter.any',
                'translation_domain'        => $domain,
                'choice_translation_domain' => $domain,
            ]);
            $this->addFilterSelect('transport', [
                'label'                     => 'history.filter.transport',
                'help'                      => false,
                'choices'                   => $transports === [] ? [] : array_combine($transports, $transports),
                'placeholder'               => 'history.filter.any',
                'translation_domain'        => $domain,
                'choice_translation_domain' => false,
            ]);
            $this->addFilterSelect('type', [
                'label'                     => 'history.filter.type',
                'help'                      => false,
                'choices'                   => $types,
                'placeholder'               => 'history.filter.any',
                'translation_domain'        => $domain,
                'choice_translation_domain' => false,
            ]);
            $this->addFilterSelect('per_page', [
                'label'                     => 'history.filter.per_page',
                'help'                      => false,
                'choices'                   => array_combine(array_map('strval', Configuration::PER_PAGE_SIZES), Configuration::PER_PAGE_SIZES),
                'placeholder'               => false,
                'required'                  => true,
                'translation_domain'        => $domain,
                'choice_translation_domain' => false,
            ]);
        });
    }

    /**
     * Options: `transport_choices` (list of names), `type_choices` (label => class).
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'transport_choices'  => [],
            'type_choices'       => [],
            'translation_domain' => NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN,
            'allow_extra_fields' => true,
        ]);
        $resolver->setAllowedTypes('transport_choices', 'array');
        $resolver->setAllowedTypes('type_choices', 'array');
    }

    /**
     * Rootless (query parameters without a form name prefix).
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
