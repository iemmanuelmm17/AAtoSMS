<?php

declare(strict_types=1);

namespace AAtoSMS;

/**
 * The addresses that requests may come from: IP_ALLOW_LIST in .env.
 *
 *     IP_ALLOW_LIST=64.113.255.88, 64.113.255.0/24
 *
 * An entry is one address, or a block of addresses: its first address, a slash and
 * the number of leading bits that all addresses of the block have in common (CIDR).
 * 64.113.255.0/24 is 64.113.255.0 to 64.113.255.255. IPv4 and IPv6 are understood.
 * Entries are separated by commas, semicolons or spaces.
 *
 * A list without entries contains no address: contains() is false for everything.
 * What a blank IP_ALLOW_LIST means - no restriction - is decided by those who use the list.
 */
final class IpList
{
    /** How an IPv4 address starts when it is written as an IPv6 address (::ffff:64.113.255.88). */
    private const IPV4_IN_IPV6 = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    private const IPV4 = '/\A(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}\z/';

    /** @var list<array{network:string, bits:int}> first address of a block as 4 or 16 bytes, and how many of its leading bits count */
    private array $blocks = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var list<array{0:string, 1:string}> */
    private array $remarks = [];

    /** @param string $text the value of IP_ALLOW_LIST */
    public function __construct(string $text)
    {
        $unreadable = [];
        // \xC2\xA0 is the space that does not break a line, which lists copied from a web page contain.
        $entries = preg_split('/(?:[\s,;]|\xC2\xA0)+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($entries as $entry) {
            $block = self::block($entry);
            if ($block === null) {
                $unreadable[] = '"' . $entry . '"';
                continue;
            }
            if ($block['bits'] === 0) {
                $this->problems[] = sprintf('IP_ALLOW_LIST: %s stands for every address there is. To accept requests from any address, leave IP_ALLOW_LIST blank.', $entry);
                continue;
            }
            $known = ['network' => $block['network'], 'bits' => $block['bits']];
            if (!in_array($known, $this->blocks, true)) {
                $this->blocks[] = $known;
            }
            if ($block['written'] !== $block['network']) {
                // 64.113.255.88/24: an address inside the block where its first address belongs.
                $this->remarks[] = [
                    sprintf('%s is read as the block %s.', $entry, self::describeBlock($known)),
                    sprintf('For that one address, write %s.', (string) inet_ntop($block['written'])),
                ];
            }
        }

        if ($unreadable !== []) {
            array_unshift($this->problems, sprintf(
                'IP_ALLOW_LIST: %s %s not an IP address or a block of addresses. Write addresses like 64.113.255.88 and blocks like 64.113.255.0/24, separated by commas.',
                implode(', ', $unreadable),
                count($unreadable) === 1 ? 'is' : 'are'
            ));
        } elseif ($entries === [] && trim($text) !== '') {
            $this->problems[] = 'IP_ALLOW_LIST contains no address.';
        }
    }

    /** True when the list names no address at all. */
    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /**
     * True when the address is on the list.
     *
     * @param string $ip the address a request came from, as the web server reports it (REMOTE_ADDR)
     */
    public function contains(string $ip): bool
    {
        // The web server reports nothing but the address. Anything else around it makes it unreadable,
        // except fe80::1%eth0: what follows the % names a network card and is no part of the address.
        $zone = strpos($ip, '%');
        $address = self::pack($zone === false ? $ip : substr($ip, 0, $zone));
        if ($address === null) {
            return false;
        }
        if (str_starts_with($address, self::IPV4_IN_IPV6)) {
            // A web server that listens on IPv6 reports an IPv4 caller as ::ffff:64.113.255.88.
            $address = substr($address, 12);
        }
        foreach ($this->blocks as $block) {
            // An IPv4 address is never in an IPv6 block, nor the other way round: 4 bytes are not 16.
            if (self::network($address, $block['bits']) === $block['network']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries in their usual form, with the first and last address of every block:
     * "64.113.255.88", "64.113.255.0/24 (64.113.255.0 to 64.113.255.255)".
     *
     * @return list<string>
     */
    public function describe(): array
    {
        return array_map(self::describeBlock(...), $this->blocks);
    }

    /**
     * What makes the value unusable, as sentences for the message about invalid settings.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Entries that are understood, but probably mean something else than their writer
     * thinks. Each remark is two lines: what the entry is read as, and what to write instead.
     *
     * @return list<array{0:string, 1:string}>
     */
    public function remarks(): array
    {
        return $this->remarks;
    }

    /**
     * One entry of the list.
     *
     * @return array{network:string, bits:int, written:string}|null network: the first address of the block;
     *                                                               written: the address as it stands in the entry;
     *                                                               null when the entry is neither an address nor a block
     */
    private static function block(string $entry): ?array
    {
        $parts = explode('/', $entry);
        $written = count($parts) <= 2 ? self::pack($parts[0]) : null;
        if ($written === null) {
            return null;
        }
        $bits = strlen($written) * 8;
        if (isset($parts[1])) {
            if (preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $parts[1]) !== 1 || (int) $parts[1] > $bits) {
                return null;
            }
            $bits = (int) $parts[1];
        }
        if ($bits >= 96 && str_starts_with($written, self::IPV4_IN_IPV6)) {
            // ::ffff:64.113.255.0/120 is the IPv4 block 64.113.255.0/24.
            $written = substr($written, 12);
            $bits -= 96;
        }

        return ['network' => self::network($written, $bits), 'bits' => $bits, 'written' => $written];
    }

    /** An address as 4 bytes (IPv4) or 16 bytes (IPv6); null when the text is not an address. */
    private static function pack(string $address): ?string
    {
        if (preg_match(self::IPV4, $address) === 1) {
            return pack('C4', ...array_map('intval', explode('.', $address)));
        }
        // Only what has a colon is left to PHP, so that the result can be nothing but an IPv6 address.
        // IPv4 is read above, the same way on every system: four numbers from 0 to 255 without leading zeros.
        if (!str_contains($address, ':') || preg_match('/\A[0-9A-Fa-f:.]{2,45}\z/', $address) !== 1) {
            return null;
        }
        $packed = @inet_pton($address);

        return is_string($packed) ? $packed : null;
    }

    /** The first address of the block that an address is in: its leading $bits bits, the rest zero. */
    private static function network(string $address, int $bits): string
    {
        $network = '';
        foreach (str_split($address) as $index => $byte) {
            $kept = max(0, min(8, $bits - 8 * $index));
            $network .= chr(ord($byte) & ((0xFF << (8 - $kept)) & 0xFF));
        }

        return $network;
    }

    /** The last address of a block: its leading $bits bits, the rest one. */
    private static function last(string $network, int $bits): string
    {
        $last = '';
        foreach (str_split($network) as $index => $byte) {
            $kept = max(0, min(8, $bits - 8 * $index));
            $last .= chr(ord($byte) | (0xFF >> $kept));
        }

        return $last;
    }

    /** @param array{network:string, bits:int} $block */
    private static function describeBlock(array $block): string
    {
        $first = (string) inet_ntop($block['network']);
        if ($block['bits'] === strlen($block['network']) * 8) {
            return $first;
        }

        return sprintf('%s/%d (%s to %s)', $first, $block['bits'], $first, (string) inet_ntop(self::last($block['network'], $block['bits'])));
    }
}
