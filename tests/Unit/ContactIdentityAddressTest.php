<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Support\ContactIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The comparison every sign-in lookup must finish with.
 *
 * Production's address columns are `utf8mb4_unicode_ci`, where the database says
 * `'victim@gmail.com' = 'victim@gmaíl.com'` and `'strasse' = 'straße'`. A query
 * on such a column returns a look-alike as a match, so the decision is made
 * here, in PHP, byte for byte. These cases are the contract: what differs only
 * by case or by surrounding whitespace is the same address, and everything else
 * — an accent, an expansion, a decomposed accent — is a different mailbox.
 */
class ContactIdentityAddressTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: string, 2: bool}>
     */
    public static function pairs(): array
    {
        return [
            'identical' => ['victim@gmail.com', 'victim@gmail.com', true],
            'case only' => ['Victim@Gmail.COM', 'victim@gmail.com', true],
            'case only, the other way' => ['victim@gmail.com', 'VICTIM@GMAIL.COM', true],
            'surrounding whitespace' => [' victim@gmail.com ', "\tvictim@gmail.com\n", true],
            'the same accented address' => ['victim@gmaíl.com', 'victim@gmaíl.com', true],
            'accented capitals fold to the same accented letters' => ['VICTIM@GMAÍL.COM', 'victim@gmaíl.com', true],

            'an accent in the domain' => ['victim@gmail.com', 'victim@gmaíl.com', false],
            'an accent in the domain, the other way' => ['victim@gmaíl.com', 'victim@gmail.com', false],
            'an accent in the local part' => ['victim@gmail.com', 'vïctim@gmail.com', false],
            'an accent on a capital' => ['victim@gmail.com', 'VÍCTIM@gmail.com', false],
            'ß is not ss' => ['strasse@example.com', 'straße@example.com', false],
            'ss is not ß' => ['straße@example.com', 'strasse@example.com', false],
            'a decomposed accent is not the composed one' => ["victim@gmai\u{0301}l.com", "victim@gma\u{00ED}l.com", false],
            'an inner space is a difference' => ['victim@gmail.com', 'vic tim@gmail.com', false],
            'a different address' => ['victim@gmail.com', 'other@gmail.com', false],

            'nothing stored' => [null, 'victim@gmail.com', false],
            'nothing typed' => ['victim@gmail.com', '', false],
            'nothing on either side' => [null, '', false],
            'empty on both sides' => ['', '', false],
            'whitespace on both sides' => ['  ', "\t", false],
        ];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function same_address_is_case_and_whitespace_blind_and_nothing_else(?string $stored, string $submitted, bool $expected): void
    {
        $this->assertSame($expected, ContactIdentity::sameAddress($stored, $submitted));
    }

    #[Test]
    public function keep_exact_matches_drops_a_candidate_that_only_the_collation_matched(): void
    {
        $exact = (new Contact())->forceFill(['login_email' => 'Victim@Gmail.com']);
        $lookAlike = (new Contact())->forceFill(['login_email' => 'victim@gmaíl.com']);
        $nothing = (new Contact())->forceFill(['login_email' => null]);

        $kept = ContactIdentity::keepExactMatches([$lookAlike, $nothing, $exact], 'login_email', 'victim@gmail.com');

        $this->assertCount(1, $kept);
        $this->assertSame($exact, $kept->first());
        $this->assertSame([0], $kept->keys()->all(), 'The result is re-indexed, so ->first() and ->count() read the same rows.');
    }

    #[Test]
    public function keep_exact_matches_keeps_every_exact_row_so_two_rows_is_still_no_row(): void
    {
        $a = (new Contact())->forceFill(['email' => 'shared@example.com']);
        $b = (new Contact())->forceFill(['email' => 'SHARED@example.com']);
        $lookAlike = (new Contact())->forceFill(['email' => 'shared@exämple.com']);

        $kept = ContactIdentity::keepExactMatches([$a, $lookAlike, $b], 'email', 'shared@example.com');

        $this->assertCount(2, $kept);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function typedAddresses(): array
    {
        return [
            'plain ASCII is only trimmed and lower-cased' => ["  Member@Example.COM \n", 'member@example.com'],
            'an ASCII address is not put through IDNA at all' => ['a_b@my_host.example.com', 'a_b@my_host.example.com'],
            'blank' => ['', null],
            'whitespace' => ["  \t", null],
            'an accent in the local part is refused' => ['vïctim@gmail.com', null],
            'a non-ASCII local part on an ASCII domain is refused' => ['ß@gmail.com', null],
            'no @ and not ASCII' => ['víctim', null],
            'nothing before the @' => ['@gmaíl.com', null],
        ];
    }

    #[Test]
    #[DataProvider('typedAddresses')]
    public function a_typed_address_is_normalised_or_refused(string $typed, ?string $expected): void
    {
        $this->assertSame($expected, ContactIdentity::submittedAddress($typed));
    }

    #[Test]
    public function an_accented_domain_becomes_punycode_and_so_can_never_equal_the_ascii_one(): void
    {
        $submitted = ContactIdentity::submittedAddress('Victim@Gmaíl.com');

        $this->assertNotNull($submitted);
        $this->assertStringStartsWith('victim@xn--', $submitted);
        $this->assertStringEndsWith('.com', $submitted);
        $this->assertSame(1, preg_match('/^[\x00-\x7F]+$/', $submitted), 'The whole address is ASCII now.');
        $this->assertFalse(ContactIdentity::sameAddress('victim@gmail.com', $submitted));
    }

    #[Test]
    public function every_label_of_a_non_ascii_domain_is_converted_and_ascii_labels_are_left_alone(): void
    {
        $submitted = ContactIdentity::submittedAddress('victim@mail.gmaíl.co.uk');

        $this->assertNotNull($submitted);
        $this->assertStringStartsWith('victim@mail.xn--', $submitted);
        $this->assertStringEndsWith('.co.uk', $submitted);
    }

    #[Test]
    public function a_domain_that_cannot_be_converted_is_refused(): void
    {
        // The IDNA rules (STD3) refuse a space inside a label, and there is no
        // ASCII fallback to hand back instead.
        $this->assertNull(ContactIdentity::submittedAddress('victim@gma íl.com'));
    }
}
