<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Tests\Unit\Fetching;

use ByteMinus\AiBlog\Fetching\RobotsRules;
use ByteMinus\AiBlog\Tests\TestCase;

class RobotsRulesTest extends TestCase
{
    public function test_the_group_for_its_own_token_wins_over_the_wildcard_group(): void
    {
        $rules = RobotsRules::parse("User-agent: *\nDisallow: /\n\nUser-agent: aiblogbot/2.0\nDisallow: /drafts/\n", 'AiBlogBot');

        $this->assertTrue($rules->allows('/guides/one'));
        $this->assertFalse($rules->allows('/drafts/x'));
    }

    public function test_the_wildcard_group_applies_when_no_group_names_the_token(): void
    {
        $rules = RobotsRules::parse("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\n", 'AiBlogBot');

        $this->assertTrue($rules->allows('/'));
        $this->assertFalse($rules->allows('/admin/settings'));
        $this->assertFalse($rules->allows('/administrator'));
    }

    public function test_several_user_agent_lines_share_one_group(): void
    {
        $rules = RobotsRules::parse("User-agent: Googlebot\nUser-agent: AiBlogBot\nDisallow: /secret\n", 'AiBlogBot');

        $this->assertFalse($rules->allows('/secret'));
    }

    public function test_the_longest_match_wins_and_a_tie_goes_to_allow(): void
    {
        $rules = RobotsRules::parse("User-agent: *\nDisallow: /shop\nAllow: /shop/guides\nDisallow: /tie\nAllow: /tie\n", 'AiBlogBot');

        $this->assertFalse($rules->allows('/shop/cart'));
        $this->assertTrue($rules->allows('/shop/guides/one'));
        $this->assertTrue($rules->allows('/tie'));
    }

    public function test_wildcards_and_end_anchors(): void
    {
        $rules = RobotsRules::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /*?session=\n", 'AiBlogBot');

        $this->assertFalse($rules->allows('/files/guide.pdf'));
        $this->assertTrue($rules->allows('/files/guide.pdf.html'));
        $this->assertFalse($rules->allows('/page?session=1'));
        $this->assertTrue($rules->allows('/page?lang=en'));
    }

    public function test_an_empty_or_missing_file_allows_everything_and_robots_txt_is_always_allowed(): void
    {
        $this->assertTrue(RobotsRules::parse('', 'AiBlogBot')->allows('/anything'));
        $this->assertTrue(RobotsRules::parse("User-agent: *\nDisallow:\n", 'AiBlogBot')->allows('/anything'));
        $this->assertTrue(RobotsRules::disallowAll()->allows('/robots.txt'));
        $this->assertFalse(RobotsRules::disallowAll()->allows('/'));
    }

    public function test_comments_case_and_odd_line_endings_are_handled(): void
    {
        $rules = RobotsRules::parse("# comment\r\nUSER-AGENT: *  # everyone\r\nDISALLOW: /Private # no\r\n", 'AiBlogBot');

        $this->assertFalse($rules->allows('/Private/a'));
        $this->assertTrue($rules->allows('/private/a'));
    }

    public function test_percent_encoding_is_compared_case_insensitively(): void
    {
        $rules = RobotsRules::parse("User-agent: *\nDisallow: /caf%c3%a9\n", 'AiBlogBot');

        $this->assertFalse($rules->allows('/caf%C3%A9/menu'));
    }
}
