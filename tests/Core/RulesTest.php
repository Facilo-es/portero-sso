<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\AccessRules;
use facilo\portero\Core\Connection;
use facilo\portero\Core\ConnectionRules;
use facilo\portero\Core\Decision;
use facilo\portero\Core\Email;
use facilo\portero\Core\Identity;
use facilo\portero\Core\Refused;
use facilo\portero\Core\ReturnPath;
use facilo\portero\Core\UrlGuard;
use facilo\portero\Core\UserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    private function identity(array $o = []): Identity
    {
        return new Identity($o['subject'] ?? '00u1', $o['email'] ?? 'Elena@Acme.com', 'Elena', 'Ruiz', $o['groups'] ?? [], $o['verified'] ?? null, $o['overage'] ?? false, $o['hd'] ?? null);
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (Refused $refused) {
            return $refused->reason;
        }
        $this->fail('Not refused');
    }

    public function testT30CapitalsChangeNothing(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena');
        $users = new MemoryUsers([$user], ['c-uid|00u1' => $user]);
        $decision = (new AccessRules($users))->decide(Support::connection(), $this->identity());
        $this->assertSame(Decision::SIGN_IN, $decision->action);
        $this->assertNull($decision->newEmail);
    }

    public function testT31EmailOwnedBySomeoneElse(): void
    {
        $me = new UserRef(5, 'old@acme.com', 'Elena');
        $other = new UserRef(6, 'new@acme.com', 'Other');
        $decision = (new AccessRules(new MemoryUsers([$me, $other], ['c-uid|00u1' => $me])))->decide(Support::connection(), $this->identity(['email' => 'new@acme.com']));
        $this->assertNull($decision->newEmail);
        $this->assertSame(['email_taken'], $decision->warnings);
    }

    public function testT32AdminMatchBlocked(): void
    {
        $admin = new UserRef(1, 'elena@acme.com', 'Admin', true);
        $connection = Support::connection(['matchByEmail' => true]);
        $this->assertSame('admin_link_blocked', $this->refusal(fn() => (new AccessRules(new MemoryUsers([$admin])))->decide($connection, $this->identity())));
    }

    public function testAdminMatchNeedsItsOwnSwitch(): void
    {
        $admin = new UserRef(1, 'elena@acme.com', 'Admin', true);
        $connection = Support::connection(['matchByEmail' => true, 'matchAdmins' => true]);
        $this->assertSame(Decision::LINK, (new AccessRules(new MemoryUsers([$admin])))->decide($connection, $this->identity())->action);
    }

    public function testMatchIgnoredWithoutDomains(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena');
        $connection = Support::connection(['matchByEmail' => true, 'allowedDomains' => []]);
        $this->assertSame('no_account', $this->refusal(fn() => (new AccessRules(new MemoryUsers([$user])))->decide($connection, $this->identity())));
    }

    public function testNeverMergesIntoAnAccountNobodyLinked(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena');
        $this->assertSame('no_account', $this->refusal(fn() => (new AccessRules(new MemoryUsers([$user])))->decide(Support::connection(), $this->identity())));
    }

    public static function badDomains(): array
    {
        return [['x@evil-acme.com'], ['x@acme.com.evil.io'], ['x@sub.acme.com']];
    }

    #[DataProvider('badDomains')]
    public function testT33DomainsAreExact(string $email): void
    {
        $this->assertSame('domain_not_allowed', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide(Support::connection(), $this->identity(['email' => $email]))));
    }

    public function testT34Suspended(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena', false, true);
        $this->assertSame('user_suspended', $this->refusal(fn() => (new AccessRules(new MemoryUsers([$user], ['c-uid|00u1' => $user])))->decide(Support::connection(), $this->identity())));
    }

    public function testT35UserLimit(): void
    {
        $this->assertSame('user_limit', $this->refusal(fn() => (new AccessRules(new MemoryUsers([], [], true)))->decide(Support::connection(), $this->identity())));
    }

    public function testT36GroupExample(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena', false, false, ['managers', 'newsletter']);
        $connection = Support::connection(['groupRules' => [['value' => 'web-editors', 'group' => 'editors'], ['value' => 'web-admins', 'group' => 'managers']]]);
        $decision = (new AccessRules(new MemoryUsers([$user], ['c-uid|00u1' => $user])))->decide($connection, $this->identity(['groups' => ['web-editors', 'all-staff']]));
        $this->assertSame(['editors'], $decision->addGroups);
        $this->assertSame(['managers'], $decision->removeGroups);
    }

    public function testT37OverageLeavesGroups(): void
    {
        $user = new UserRef(5, 'elena@acme.com', 'Elena', false, false, ['managers']);
        $connection = Support::connection(['groupRules' => [['value' => 'x', 'group' => 'managers']]]);
        $decision = (new AccessRules(new MemoryUsers([$user], ['c-uid|00u1' => $user])))->decide($connection, $this->identity(['overage' => true]));
        $this->assertSame([], $decision->removeGroups);
        $this->assertSame(['groups_overage'], $decision->warnings);
    }

    public function testOverageFailsClosedWhenAGroupIsRequired(): void
    {
        $connection = Support::connection(['requireGroupMatch' => true]);
        $this->assertSame('no_group_match', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide($connection, $this->identity(['overage' => true]))));
    }

    public function testT38AdminRuleRemoves(): void
    {
        $admin = new UserRef(5, 'elena@acme.com', 'Elena', true, false, [], 'u-5');
        $connection = Support::connection(['adminRule' => 'web-admins']);
        $users = new MemoryUsers([$admin], ['c-uid|00u1' => $admin]);
        $this->assertFalse((new AccessRules($users))->decide($connection, $this->identity())->makeAdmin);
        $this->assertNull((new AccessRules($users, ['u-5']))->decide($connection, $this->identity())->makeAdmin);
    }

    public function testT37OverageLeavesAdminAlone(): void
    {
        $admin = new UserRef(5, 'elena@acme.com', 'Elena', true);
        $connection = Support::connection(['adminRule' => 'web-admins']);
        $users = new MemoryUsers([$admin], ['c-uid|00u1' => $admin]);
        $this->assertNull((new AccessRules($users))->decide($connection, $this->identity(['overage' => true]))->makeAdmin);
    }

    public function testEmailListRefused(): void
    {
        $this->assertSame('missing_email', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide(Support::connection(), $this->identity(['email' => 'nobody@x.io,paula@acme.com']))));
    }

    public function testAdminRuleGrants(): void
    {
        $connection = Support::connection(['adminRule' => 'web-admins']);
        $this->assertTrue((new AccessRules(new MemoryUsers()))->decide($connection, $this->identity(['groups' => ['web-admins']]))->makeAdmin);
    }

    public function testT29EmailNotVerified(): void
    {
        $this->assertSame('email_not_verified', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide(Support::connection(['protocol' => Connection::OIDC]), $this->identity(['verified' => false]))));
    }

    public function testOidcMissingVerifiedClaimNeedsDomains(): void
    {
        $connection = Support::connection(['protocol' => Connection::OIDC, 'allowedDomains' => []]);
        $this->assertSame('missing_email', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide($connection, $this->identity())));
    }

    public function testHostedDomainChecked(): void
    {
        $this->assertSame('domain_not_allowed', $this->refusal(fn() => (new AccessRules(new MemoryUsers()))->decide(Support::connection(), $this->identity(['hd' => 'evil.com']))));
    }

    public static function badReturns(): array
    {
        return [['//evil.com'], ['https://evil.com'], ['/\evil.com'], ['/%2F%2Fevil.com'], ['/%5Cevil.com'], ['evil.com'], ['/%252F/evil.com'], ["/x\r\nSet-Cookie: a=b"], ['']];
    }

    #[DataProvider('badReturns')]
    public function testT39UnsafeReturnsDropped(string $path): void
    {
        $this->assertNull(ReturnPath::safe($path));
    }

    public function testSafeReturnKept(): void
    {
        $this->assertSame('/members?a=1', ReturnPath::safe('/members?a=1'));
    }

    public function testT28MultiTenantRefused(): void
    {
        foreach (['common', 'organizations', 'consumers'] as $tenant) {
            $errors = ConnectionRules::check(['name' => 'E', 'handle' => 'e', 'protocol' => Connection::OIDC, 'oidc' => ['issuer' => "https://login.microsoftonline.com/$tenant/v2.0"]]);
            $this->assertArrayHasKey('issuer', $errors);
        }
    }

    public function testT51TypedSecretRefused(): void
    {
        $this->assertArrayHasKey('clientSecret', ConnectionRules::check(['name' => 'E', 'handle' => 'e', 'protocol' => Connection::OIDC, 'oidc' => ['clientSecret' => 'abc123']]));
        $this->assertSame([], ConnectionRules::check(['name' => 'E', 'handle' => 'e', 'protocol' => Connection::OIDC, 'oidc' => ['clientSecret' => '$OKTA_SECRET']]));
    }

    public function testT60SigningWithoutFormatRefused(): void
    {
        $this->assertArrayHasKey('nameIdFormat', ConnectionRules::check(['name' => 'E', 'handle' => 'e', 'protocol' => Connection::SAML, 'saml' => ['signRequests' => true]]));
    }

    public function testMaskEmail(): void
    {
        $this->assertSame('el***@example.com', Email::mask('elena@example.com'));
        $this->assertNull(Email::mask(null));
    }

    public function testUrlGuard(): void
    {
        $public = fn() => ['93.184.216.34'];
        $private = fn() => ['10.0.0.1'];
        $this->assertTrue(UrlGuard::allowed('https://idp.example/x', $public));
        $this->assertFalse(UrlGuard::allowed('http://idp.example/x', $public));
        $this->assertFalse(UrlGuard::allowed('https://idp.example/x', $private));
        $this->assertFalse(UrlGuard::allowed('https://169.254.169.254/x', $public));
        $this->assertFalse(UrlGuard::allowed('https://idp.example/x', fn() => ['::ffff:10.0.0.1']));
        $this->assertFalse(UrlGuard::allowed('https://idp.example/x', fn() => ['100.64.1.1']));
    }

    public function testCoreHasNoCraftClasses(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Core', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/^\s*use\s+(craft|yii|Craft)\b|\bCraft::|\byii\\\\/m', file_get_contents((string) $file))) {
                $hits[] = (string) $file;
            }
        }
        $this->assertSame([], $hits);
    }
}
