<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class AuthenticationTest extends AccountWebTestCase
{
    public function testPasswordLoginAndCsrfProtectedLogoutManageTheSession(): void
    {
        $this->createUser('member@example.com', true, 'member-password');
        $crawler = $this->client->request('GET', $this->url('app_login'));
        $form = $crawler->filter('form[action="'.$this->url('app_login').'"]')->form([
            '_username' => 'MEMBER@EXAMPLE.COM',
            '_password' => 'member-password',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects($this->url('app_dashboard'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->client->request('POST', $this->url('app_logout'), [
            '_csrf_token' => $this->csrfToken('logout'),
        ]);
        self::assertResponseRedirects($this->url('app_home'));

        $this->client->request('GET', $this->url('app_account'));
        self::assertResponseRedirects($this->url('app_login'));
    }

    public function testUnknownMagicLinkRequestsRemainNeutralAndSendNoEmail(): void
    {
        $crawler = $this->client->request('GET', $this->url('app_login'));
        $form = $crawler->filter('form[action="'.$this->url('app_login_link_request').'"]')->form([
            'form[email]' => 'missing@example.com',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(0);
    }

    public function testValidMagicLinkConfirmsIntentAuthenticatesAndVerifiesTheAccount(): void
    {
        $user = $this->createUser('magic@example.com');
        $crawler = $this->client->request('GET', $this->url('app_login'));
        $form = $crawler->filter('form[action="'.$this->url('app_login_link_request').'"]')->form([
            'form[email]' => 'magic@example.com',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->url('app_check_email'));
        self::assertEmailCount(1);
        $link = $this->emailLink();
        self::assertSame($this->url('app_login_link_check'), parse_url($link, PHP_URL_PATH));

        $user = $this->refreshUser($user);
        $user->setVerified(false);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login_link_check').'"]')->form());

        self::assertResponseRedirects($this->url('app_dashboard'));
        self::assertTrue($this->refreshUser($user)->isVerified());
        $this->client->request('GET', $this->url('app_account'));
        self::assertResponseIsSuccessful();
    }

    public function testInvalidExpiredAndAlreadyConsumedMagicLinksAreRejected(): void
    {
        $user = $this->createUser('single-use@example.com');
        $crawler = $this->client->request('GET', $this->url('app_login'));
        $requestForm = $crawler->filter('form[action="'.$this->url('app_login_link_request').'"]')->form([
            'form[email]' => 'single-use@example.com',
        ]);
        $this->client->submit($requestForm);
        $link = $this->emailLink();
        $expiredLink = $this->magicLink($this->refreshUser($user), -1);

        $crawler = $this->client->request('GET', $expiredLink);
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login_link_check').'"]')->form());
        self::assertResponseRedirects($this->url('app_login'));

        $crawler = $this->client->request('GET', $this->replaceQueryParameter($link, 'hash', 'invalid'));
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login_link_check').'"]')->form());
        self::assertResponseRedirects($this->url('app_login'));

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login_link_check').'"]')->form());
        self::assertResponseRedirects($this->url('app_dashboard'));

        $this->client->request('POST', $this->url('app_logout'), [
            '_csrf_token' => $this->csrfToken('logout'),
        ]);
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->filter('form[action="'.$this->url('app_login_link_check').'"]')->form());
        self::assertResponseRedirects($this->url('app_login'));
    }

    private function replaceQueryParameter(string $url, string $name, string $value): string
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);
        $query[$name] = $value;

        return ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost')
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '').'?'.http_build_query($query);
    }
}
