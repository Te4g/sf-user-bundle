<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class LocalePreferenceTest extends AccountWebTestCase
{
    public function testBrowserCookieAndEnglishFallbackResolveInOrder(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr-FR,fr;q=0.9,en;q=0.8');
        $crawler = $this->client->request('GET', $this->url('app_home'));

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSame('fr', $this->localeForm($crawler)['locale']->getValue());

        $this->client->getCookieJar()->set(new BrowserCookie('app_locale', 'en'));
        $crawler = $this->client->request('GET', $this->url('app_home'));

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSame('en', $this->localeForm($crawler)['locale']->getValue());

        $this->client->getCookieJar()->expire('app_locale');
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'de-DE,de;q=0.9');
        $crawler = $this->client->request('GET', $this->url('app_home'));

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSame('en', $this->localeForm($crawler)['locale']->getValue());
    }

    public function testAuthenticatedPreferenceOverridesCookieAndPersistsAChange(): void
    {
        $user = $this->createUser('locale@example.com', locale: 'fr');
        $this->client->getCookieJar()->set(new BrowserCookie('app_locale', 'en'));
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', $this->url('app_dashboard'));
        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        $form = $this->localeForm($crawler);
        self::assertSame('fr', $form['locale']->getValue());

        $this->client->submit($form, ['locale' => 'en']);

        self::assertResponseRedirects($this->url('app_dashboard'));
        self::assertSame('en', $this->refreshUser($user)->getLocale());
        $cookie = $this->client->getCookieJar()->get('app_locale');
        self::assertInstanceOf(BrowserCookie::class, $cookie);
        self::assertSame('en', $cookie->getValue());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    public function testLocaleChangeRejectsInvalidCsrfAndUnsupportedLocales(): void
    {
        $user = $this->createUser('rejected-locale@example.com', locale: 'fr');
        $this->client->getCookieJar()->set(new BrowserCookie('app_locale', 'en'));
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->url('app_dashboard'));
        $form = $this->localeForm($crawler);
        $localeBefore = $this->refreshUser($user)->getLocale();
        $cookieBefore = $this->client->getCookieJar()->get('app_locale');
        self::assertInstanceOf(BrowserCookie::class, $cookieBefore);

        $this->client->submit($form, [
            'locale' => 'en',
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame($localeBefore, $this->refreshUser($user)->getLocale());
        self::assertSame($cookieBefore->getValue(), $this->client->getCookieJar()->get('app_locale')?->getValue());

        $crawler = $this->client->request('GET', $this->url('app_dashboard'));
        $form = $this->localeForm($crawler);
        $this->client->submit($form, ['locale' => 'de']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame($localeBefore, $this->refreshUser($user)->getLocale());
        self::assertSame($cookieBefore->getValue(), $this->client->getCookieJar()->get('app_locale')?->getValue());
    }

    public function testLocaleChangeRejectsExternalAndBackslashReturnTargets(): void
    {
        foreach (['https://evil.example', '//evil.example'] as $target) {
            $crawler = $this->client->request('GET', $this->url('app_home'));
            $this->client->submit($this->localeForm($crawler), [
                'locale' => 'fr',
                'return_to' => $target,
            ]);

            self::assertResponseRedirects($this->url('app_home'));
        }

        $user = $this->createUser('safe-redirect@example.com');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->url('app_dashboard'));
        $this->client->submit($this->localeForm($crawler), [
            'locale' => 'fr',
            'return_to' => '/\\evil.example',
        ]);

        self::assertResponseRedirects($this->url('app_dashboard'));
    }

    public function testRegistrationPersistsTheResolvedLocale(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr-FR,fr;q=0.9,en;q=0.8');
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $this->localeForm($crawler);
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Locale User',
            'registration[email]' => 'locale-registration@example.com',
            'registration[plainPassword][first]' => 'correct horse battery staple',
            'registration[plainPassword][second]' => 'correct horse battery staple',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects($this->url('app_check_email'));
        $user = $this->user('locale-registration@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame('fr', $this->refreshUser($user)->getLocale());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Vérifiez votre adresse e-mail', $email->getSubject());
        self::assertStringContainsString('Vérifiez votre adresse e-mail', (string) $email->getHtmlBody());
    }

    public function testRegistrationRendersMismatchedPasswordsInFrench(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr-FR,fr;q=0.9,en;q=0.8');
        $crawler = $this->client->request('GET', $this->url('app_register'));
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[name]' => 'Locale User',
            'registration[email]' => 'locale-mismatch@example.com',
            'registration[plainPassword][first]' => 'correct horse battery staple',
            'registration[plainPassword][second]' => 'different horse battery staple',
        ]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Les champs de mot de passe doivent correspondre.', $content);
        self::assertStringNotContainsString('The password fields must match.', $content);
    }

    private function localeForm(Crawler $crawler): Form
    {
        $forms = $crawler->filter('form[action="'.$this->url('app_locale').'"]');
        self::assertCount(1, $forms);

        return $forms->form();
    }
}
