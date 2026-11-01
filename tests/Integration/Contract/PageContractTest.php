<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Contract;

use Nowo\MessengerMonitorKitBundle\Tests\Support\PageFixtures;
use Nowo\MessengerMonitorKitBundle\Twig\MessengerMonitorTwigExtension;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;

use function dirname;

/**
 * REQ-UI-003 page contract: every page extends a UiKit scaffold; no <html>, CDN, inline handler or confirm(); framework
 * classes only through theme slots; the stub theme is reflected; no raw or missing translation (strict); the CSRF
 * action forms keep their hidden fields; payloads and failure messages are printed redacted.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $dir = '@NowoMessengerMonitorKitBundle/monitor/';
        yield 'dashboard' => new PageFixture($dir . 'dashboard.html.twig', PageFixtures::dashboard());
        yield 'dashboard (empty)' => new PageFixture($dir . 'dashboard.html.twig', PageFixtures::dashboard(true));
        yield 'history' => new PageFixture($dir . 'history.html.twig', PageFixtures::history());
        yield 'history (empty)' => new PageFixture($dir . 'history.html.twig', PageFixtures::history(true));
        yield 'history row (manager)' => new PageFixture($dir . 'history_show.html.twig', PageFixtures::historyShow());
        yield 'history row (viewer)' => new PageFixture($dir . 'history_show.html.twig', PageFixtures::historyShow(false));
        yield 'transports' => new PageFixture($dir . 'transports.html.twig', PageFixtures::transports());
        yield 'failure transport' => new PageFixture($dir . 'transport.html.twig', PageFixtures::transport());
        yield 'queue transport' => new PageFixture($dir . 'transport.html.twig', PageFixtures::transport(false));
        yield 'empty transport' => new PageFixture($dir . 'transport.html.twig', PageFixtures::transport(true, true));
        yield 'message (manager)' => new PageFixture($dir . 'message.html.twig', PageFixtures::message());
        yield 'message (duplicate risk)' => new PageFixture($dir . 'message.html.twig', PageFixtures::message(true, true));
        yield 'message (viewer)' => new PageFixture($dir . 'message.html.twig', PageFixtures::message(false));
        yield 'settings' => new PageFixture($dir . 'settings.html.twig', PageFixtures::settings());
        yield 'settings (read-only)' => new PageFixture($dir . 'settings.html.twig', PageFixtures::settings(false));
    }

    /**
     * Personal data of the fixtures never reach the HTML.
     */
    #[DataProvider('contractPages')]
    public function testPageNeverPrintsPersonalData(PageFixture $fixture): void
    {
        foreach (['none', 'bootstrap5', 'tailwind'] as $framework) {
            $html = $this->renderPage($fixture, $framework)->html;
            self::assertStringNotContainsString(PageFixtures::EMAIL, $html);
            self::assertStringNotContainsString(PageFixtures::TOKEN, $html);
        }
    }

    protected function configureTwig(Environment $twig): void
    {
        $twig->addExtension(new MessengerMonitorTwigExtension());
    }

    protected static function templatePaths(): array
    {
        return ['NowoMessengerMonitorKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations', dirname(__DIR__, 2) . '/Fixtures/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
