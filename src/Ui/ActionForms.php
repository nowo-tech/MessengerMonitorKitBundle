<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Ui;

use Nowo\FormKitBundle\Form\Type\CsrfOnlyType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function hash;
use function substr;

/**
 * CSRF-only forms (FormKit {@see CsrfOnlyType}) of the failure transport actions, scoped to one action and one target:
 * the CSRF token id and the form name derive from the route, the transport and the message id (or "all"), so a token
 * rendered for "retry message 1" is refused by "remove all" or by another message. The page factory renders them and
 * the controllers validate them with the same arguments. Stateless.
 */
final readonly class ActionForms
{
    /** Prefix of every action token id. */
    public const TOKEN_PREFIX = 'nowo_mmk';

    /**
     * @param FormFactoryInterface $formFactory Symfony form factory
     * @param UrlGeneratorInterface $urls route URLs (form action)
     */
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * CSRF token id of an action on a target: `nowo_mmk.<route>.<transport>.<message id|all>`.
     *
     * @param array<string, string> $params route parameters (`name`, optional `id`)
     */
    public static function tokenId(string $route, array $params): string
    {
        return self::TOKEN_PREFIX . '.' . $route . '.' . ($params['name'] ?? '') . '.' . ($params['id'] ?? 'all');
    }

    /**
     * Form name of an action on a target (unique per token id, so one page can render many action forms).
     *
     * @param array<string, string> $params
     */
    public static function formName(string $route, array $params): string
    {
        return 'mmk_' . substr(hash('xxh128', self::tokenId($route, $params)), 0, 16);
    }

    /**
     * The POST form of an action on a target (action URL generated from the route).
     *
     * @param array<string, string> $params
     *
     * @return FormInterface<mixed>
     */
    public function create(string $route, array $params): FormInterface
    {
        return $this->formFactory->createNamed(self::formName($route, $params), CsrfOnlyType::class, null, [
            'action'          => $this->urls->generate($route, $params),
            'method'          => 'POST',
            'csrf_token_id'   => self::tokenId($route, $params),
            'csrf_field_name' => '_token',
        ]);
    }
}
