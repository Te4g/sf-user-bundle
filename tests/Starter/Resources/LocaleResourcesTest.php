<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class LocaleResourcesTest extends TestCase
{
    public function testLocaleConfigurationDefinesSupportedLocalesAndEnglishFallback(): void
    {
        $resource = $this->applicationResources().'/config/packages/account_locale.yaml';
        self::assertFileExists($resource);
        $config = Yaml::parseFile($resource);

        self::assertSame('en', $config['framework']['default_locale']);
        self::assertSame(['en'], $config['framework']['translator']['fallbacks']);
        self::assertSame(['en' => 'English', 'fr' => 'Français'], $config['parameters']['app.supported_locales']);
    }

    public function testLocaleSubscriberUpdatesTheRequestAndLocaleAwareServicesOnMainRequests(): void
    {
        $source = $this->resource('src/EventSubscriber/LocaleSubscriber.php');

        self::assertStringContainsString("KernelEvents::REQUEST => ['onKernelRequest', 0]", $source);
        self::assertStringContainsString('$event->isMainRequest()', $source);
        self::assertStringContainsString('$request->setLocale($locale)', $source);
        self::assertStringContainsString('$this->localeSwitcher->setLocale($locale)', $source);
    }

    public function testLocaleControllerValidatesTheRequestAndSetsASafePersistentCookie(): void
    {
        $controller = $this->resource('src/Controller/LocaleController.php');

        self::assertStringContainsString("#[Route('/locale', name: 'app_locale', methods: ['POST'])]", $controller);
        self::assertStringContainsString("new CsrfToken('locale_change'", $controller);
        self::assertStringContainsString('Response::HTTP_UNPROCESSABLE_ENTITY', $controller);
        self::assertStringContainsString("Cookie::create('app_locale')", $controller);
        self::assertStringContainsString("withExpires(new \\DateTimeImmutable('+1 year'))", $controller);
        self::assertStringContainsString("withPath('/')", $controller);
        self::assertStringContainsString('withSecure($request->isSecure())', $controller);
        self::assertStringContainsString('withHttpOnly(true)', $controller);
        self::assertStringContainsString('Cookie::SAMESITE_LAX', $controller);
        self::assertStringContainsString("str_starts_with(\$target, '/')", $controller);
        self::assertStringContainsString("str_starts_with(\$target, '//')", $controller);
        self::assertStringContainsString("str_contains(\$target, '\\\\')", $controller);
    }

    public function testEveryLayoutRendersOneNoJavascriptLanguageSwitcher(): void
    {
        $component = $this->resource('templates/components/LanguageSwitcher.html.twig');

        self::assertStringContainsString("path('app_locale')", $component);
        self::assertStringContainsString('name="locale"', $component);
        self::assertStringContainsString('supported_locales', $component);
        self::assertStringContainsString('app.request.locale', $component);
        self::assertStringContainsString('name="_token"', $component);
        self::assertStringContainsString("csrf_token('locale_change')", $component);
        self::assertStringContainsString('name="return_to"', $component);
        self::assertStringContainsString('value="{{ app.request.requestUri }}"', $component);
        self::assertStringContainsString("'locale.label'|trans", $component);
        self::assertStringContainsString("'locale.apply'|trans", $component);
        self::assertStringNotContainsString('data-controller', $component);

        foreach (['public', 'auth', 'app'] as $layout) {
            self::assertSame(
                1,
                substr_count($this->resource('templates/layout/'.$layout.'.html.twig'), '<twig:LanguageSwitcher />'),
                $layout,
            );
        }
    }

    public function testEnglishAndFrenchTranslationCataloguesExposeTheSameKeys(): void
    {
        self::assertSame($this->translationIds('messages.en.xlf'), $this->translationIds('messages.fr.xlf'));
        self::assertSame($this->translationIds('validators.en.xlf'), $this->translationIds('validators.fr.xlf'));
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testGenericApplicationServiceDiscoveryCanAutowireSupportedLocales(): void
    {
        $this->requireApplicationClass('src/Locale/SupportedLocales.php');

        $supportedLocalesClass = $this->applicationClass('Locale\\SupportedLocales');
        $container = new ContainerBuilder();
        $container->setParameter('app.supported_locales', ['en' => 'English', 'fr' => 'Français']);
        $container->setParameter('kernel.default_locale', 'en');
        $container->register($supportedLocalesClass, $supportedLocalesClass)
            ->setAutowired(true)
            ->setPublic(true);

        $container->compile();

        $locales = $container->get($supportedLocalesClass);
        self::assertInstanceOf($supportedLocalesClass, $locales);
        self::assertSame(['en', 'fr'], (new \ReflectionMethod($supportedLocalesClass, 'codes'))->invoke($locales));
        self::assertSame('en', (new \ReflectionMethod($supportedLocalesClass, 'default'))->invoke($locales));
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testLocaleResolverPrefersUserThenCookieThenRequestLanguageThenDefault(): void
    {
        $this->requireApplicationClass('src/Entity/User.php');
        $this->requireApplicationClass('src/Locale/SupportedLocales.php');

        $supportedLocalesClass = $this->applicationClass('Locale\\SupportedLocales');
        $userClass = $this->applicationClass('Entity\\User');
        $locales = new $supportedLocalesClass(['en' => 'English', 'fr' => 'Français'], 'en');
        $resolve = new \ReflectionMethod($supportedLocalesClass, 'resolve');
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);

        self::assertSame('fr', $resolve->invoke($locales, $request, null));

        $request->cookies->set('app_locale', 'en');
        self::assertSame('en', $resolve->invoke($locales, $request, null));

        $user = new $userClass();
        (new \ReflectionMethod($userClass, 'setLocale'))->invoke($user, 'fr');
        self::assertSame('fr', $resolve->invoke($locales, $request, $user));

        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
        self::assertSame('en', $resolve->invoke($locales, $request, null));
    }

    private function resource(string $path): string
    {
        $resource = $this->applicationResources().'/'.$path;
        self::assertFileExists($resource);
        $source = file_get_contents($resource);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @return list<string> */
    private function translationIds(string $filename): array
    {
        $catalogue = $this->applicationResources().'/translations/'.$filename;
        self::assertFileExists($catalogue);

        $document = new \DOMDocument();
        self::assertTrue($document->load($catalogue, LIBXML_NONET), $filename);

        $sources = (new \DOMXPath($document))->query("//*[local-name()='source']");
        self::assertNotFalse($sources);

        $ids = [];
        foreach ($sources as $source) {
            self::assertInstanceOf(\DOMNode::class, $source);
            $ids[] = $source->textContent;
        }
        sort($ids);

        return $ids;
    }

    private function requireApplicationClass(string $path): void
    {
        $resource = $this->applicationResources().'/'.$path;
        self::assertFileExists($resource);
        require_once $resource;
    }

    /** @return class-string */
    private function applicationClass(string $suffix): string
    {
        $class = 'App\\'.$suffix;
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Application class "%s" was not loaded.', $class));
        }

        return $class;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}
