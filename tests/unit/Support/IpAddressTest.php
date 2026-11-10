<?php
declare(strict_types=1);

namespace Energietracker\Tests\Support;

use Energietracker\Services\HomeAssistant\NotifyTransport;
use Energietracker\Services\Inbox\CloudHttp;
use Energietracker\Services\OcrService;
use Energietracker\Support\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.12.0 — Härtung: eine Prüfung für Zieladressen.
 *
 * Drei Wege verbinden sich mit einer Adresse, die jemand einträgt: Texterkennung
 * und evcc (nur Heimnetz), Benachrichtigungen an Home Assistant (nie Link-local
 * oder unspezifiziert) und das Auslesen in der Cloud (nur öffentlich). Eine IPv4
 * lässt sich in IPv6 auf viele Arten schreiben. Bis v3.11 erkannte jeder Weg
 * andere davon; Home Assistant ließ die Hex-Schreibweise von `::ffff:…` und NAT64
 * auf Link-local durch, die Cloud hielt `::a.b.c.d` auf Link-local für öffentlich.
 *
 * Die Regel, an einer Stelle (Support\IpAddress):
 *   - Die Sperrliste (Home Assistant) sieht durch jede Hülle auf die IPv4.
 *   - „Nur Heimnetz“ nimmt nur IPv4 und `::ffff:…` an: Damit verbindet das
 *     eigene System selbst über IPv4. NAT64, 6to4 und `::a.b.c.d` laufen über
 *     fremde Übersetzer und sind nie Heimnetz.
 *   - „Nur öffentlich“ nimmt IPv4, `::ffff:…`, NAT64 (64:ff9b::/96) und 6to4 an,
 *     wenn die eingebettete IPv4 öffentlich ist; die veralteten Formen
 *     `::a.b.c.d` und `::ffff:0:a.b.c.d` und das lokale NAT64-Präfix nie.
 */
final class IpAddressTest extends TestCase
{
    /**
     * Jede IPv4 in jeder Schreibweise. Hex von Hand: 169.254.10.20 = a9fe:0a14,
     * 127.0.0.1 = 7f00:0001, 192.168.178.20 = c0a8:b214, 9.9.9.9 = 0909:0909,
     * 0.0.0.0 = 0000:0000.
     *
     * @return array<string, array{string, string}> Schreibweise → [Adresse, Form]
     */
    private static function forms(string $v4, string $hi, string $lo): array
    {
        return [
            "IPv4 $v4"                       => [$v4, 'ipv4'],
            "::ffff: mit Punkten ($v4)"      => ["::ffff:$v4", 'mapped'],
            "::ffff: hexadezimal ($v4)"      => ["::ffff:$hi:$lo", 'mapped'],
            "ausgeschrieben ($v4)"           => ["0:0:0:0:0:ffff:$hi:$lo", 'mapped'],
            "Nullen, Großbuchstaben ($v4)"   => ["0000:0000:0000:0000:0000:FFFF:$v4", 'mapped'],
            "IPv4-kompatibel hex ($v4)"      => ["::$hi:$lo", 'compatible'],
            "IPv4-kompatibel Punkte ($v4)"   => ["::$v4", 'compatible'],
            "NAT64 hex ($v4)"                => ["64:ff9b::$hi:$lo", 'nat64'],
            "NAT64 Punkte ($v4)"             => ["64:ff9b::$v4", 'nat64'],
            "6to4 ($v4)"                     => ["2002:$hi:$lo::1", '6to4'],
            "IPv4-übersetzt ($v4)"           => ["::ffff:0:$hi:$lo", 'translated'],
        ];
    }

    /**
     * Erwartung je IPv4: [Heimnetz?, gesperrt für Home Assistant?, öffentlich?].
     * 0.0.0.0 und `::` zählen bei der leeren IPv4 nicht als kompatibel, sondern
     * als unspezifiziert (dieselbe Adresse).
     */
    private const EXPECT = [
        //                   v4                 hi      lo      lokal  gesperrt öffentlich
        'link-local'  => ['169.254.10.20',  'a9fe', '0a14', false, true,  false],
        'unspez.'     => ['0.0.0.0',        '0000', '0000', false, true,  false],
        'loopback'    => ['127.0.0.1',      '7f00', '0001', true,  false, false],
        'heimnetz'    => ['192.168.178.20', 'c0a8', 'b214', true,  false, false],
        'öffentlich'  => ['9.9.9.9',        '0909', '0909', false, false, true],
        // v3.12.0 — Metadatendienst in 100.64.0.0/10 (100.100.100.200 = 6464:64c8): überall gesperrt
        'metadaten'   => ['100.100.100.200', '6464', '64c8', false, true,  false],
    ];

    /** @return array<string, array{string, string, bool, bool, bool}> */
    public static function cases(): array
    {
        $out = [];
        foreach (self::EXPECT as $label => [$v4, $hi, $lo, $local, $blocked, $public]) {
            foreach (self::forms($v4, $hi, $lo) as $name => [$ip, $form]) {
                // „Nur Heimnetz“: nur IPv4 und ::ffff:…
                $l = $local && in_array($form, ['ipv4', 'mapped'], true);
                // „Nur öffentlich“: dazu NAT64 und 6to4, nie die veralteten Formen
                $p = $public && in_array($form, ['ipv4', 'mapped', 'nat64', '6to4'], true);
                $out["$label: $name"] = [$ip, $form, $l, $blocked, $p];
            }
        }
        return $out;
    }

    #[DataProvider('cases')]
    public function testEveryFormOfAnEmbeddedIpv4CountsAlike(string $ip, string $form, bool $local, bool $blocked, bool $public): void
    {
        // :: (= ::0.0.0.0) ist die unspezifizierte IPv6, keine IPv4-kompatible Adresse
        $expectForm = $ip === '::0000:0000' || $ip === '::0.0.0.0' ? 'ipv6' : $form;
        self::assertSame($expectForm, IpAddress::parse($ip)['form'], "$ip: Form");
        self::assertSame($local, OcrService::isLocalIp($ip), "$ip: Heimnetz (Texterkennung, evcc)");
        self::assertSame($blocked, NotifyTransport::forbidden($ip), "$ip: gesperrt für Home Assistant");
        self::assertSame($public, CloudHttp::isPublicIp($ip), "$ip: öffentlich (Cloud)");
    }

    /** ::ffff:7f00:1 ist 127.0.0.1: überall wie 127.0.0.1, nicht strenger. */
    public function testMappedLoopbackInHexIsLoopbackEverywhere(): void
    {
        foreach (['::ffff:7f00:1', '127.0.0.1'] as $ip) {
            self::assertSame('127.0.0.1', IpAddress::parse($ip)['ipv4'], $ip);
            self::assertTrue(OcrService::isLocalIp($ip), $ip);
            self::assertFalse(NotifyTransport::forbidden($ip), $ip);
            self::assertFalse(CloudHttp::isPublicIp($ip), $ip);
        }
    }

    /** Das lokale NAT64-Präfix (RFC 8215) legt die Lage der IPv4 nicht fest: nirgends zugelassen. */
    public function testLocalNat64PrefixIsNeverAllowed(): void
    {
        foreach (['64:ff9b:1::a9fe:a14', '64:ff9b:1:c0a8:b2:1400::'] as $ip) {
            self::assertSame('nat64_local', IpAddress::parse($ip)['form'], $ip);
            self::assertFalse(OcrService::isLocalIp($ip), $ip);
            self::assertTrue(NotifyTransport::forbidden($ip), $ip);
            self::assertFalse(CloudHttp::isPublicIp($ip), $ip);
        }
    }

    /** Reine IPv6 und Unsinn bleiben, wie sie waren. */
    public function testPlainIpv6AndGarbage(): void
    {
        $plain = [
            // Adresse        lokal  gesperrt öffentlich
            ['::1',           true,  false, false],
            ['::',            false, true,  false],
            ['fe80::1',       false, true,  false],
            ['fd00:ec2::254', false, true,  false],
            ['fd12:3456::1',  true,  false, false],
            ['2606:4700::1111', false, false, true],
            ['ff02::1',       false, false, false],
            // „dieses Netz“ 0.0.0.0/8, nicht nur 0.0.0.0 (0.1.2.3 = ::ffff:0001:0203)
            ['0.1.2.3',       false, true,  false],
            ['::ffff:1:203',  false, true,  false],
            ['kein-ip',       false, true,  false],
            ['',              false, true,  false],
        ];
        foreach ($plain as [$ip, $local, $blocked, $public]) {
            self::assertSame($local, OcrService::isLocalIp($ip), "$ip: Heimnetz");
            self::assertSame($blocked, NotifyTransport::forbidden($ip), "$ip: gesperrt");
            self::assertSame($public, CloudHttp::isPublicIp($ip), "$ip: öffentlich");
        }
    }

    /** Was die Sperrliste trifft, ist weder Heimnetz noch öffentlich — für jede Form. */
    public function testBlockedIsNeverLocalOrPublic(): void
    {
        foreach (self::cases() as [$ip]) {
            if (!NotifyTransport::forbidden($ip)) continue;
            self::assertFalse(OcrService::isLocalIp($ip), "$ip: gesperrt, aber Heimnetz");
            self::assertFalse(CloudHttp::isPublicIp($ip), "$ip: gesperrt, aber öffentlich");
        }
    }
}
