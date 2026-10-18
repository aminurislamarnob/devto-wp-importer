<?php

use PHPUnit\Framework\TestCase;
use WeLabs\DevtoWpImporter\Engagement\Comments;

/**
 * Frontend comments are Markdown: whatever a reader types, code samples
 * included, has to come back from storage exactly as written.
 */
class CommentMarkdownTest extends TestCase {

	/**
	 * @dataProvider markdown_samples
	 */
	public function test_markdown_survives_storage( $markdown ) {
		$stored = Comments::encode_markdown( $markdown );

		$this->assertSame( $markdown, Comments::decode_markdown( $stored ) );
	}

	public function markdown_samples() {
		return [
			'html in a code block'   => [ "```html\n<div class=\"card\">Hi</div>\n```" ],
			'php open tag'           => [ '`<?php echo $x; ?>`' ],
			'literal entity'         => [ 'Write &amp; as `&amp;amp;`' ],
			'url with escapes'       => [ 'See https://example.com/a%20b?x=1&y=2' ],
			'quotes and apostrophes' => [ "It's \"quoted\"" ],
			'emoji and bangla'       => [ "👩‍💻 ক্ষ বাংলা" ],
		];
	}

	public function test_stored_markdown_has_no_raw_tags_for_kses_to_strip() {
		$stored = Comments::encode_markdown( '<script>alert(1)</script> & <b>x</b>' );

		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringNotContainsString( '>', $stored );
		$this->assertSame( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;x&lt;/b&gt;', $stored );
	}

	public function test_clean_normalises_line_endings_and_trims() {
		$this->assertSame( "line one\nline two\n\nline three", Comments::clean_markdown( "  line one\r\nline two\r\n\rline three \n" ) );
	}

	public function test_clean_drops_control_characters_but_keeps_tabs_and_joiners() {
		$this->assertSame( "a\tb👩‍💻", Comments::clean_markdown( "a\x00\tb\x07👩‍💻" ) );
	}

	public function test_clean_rejects_invalid_utf8() {
		$this->assertSame( '', Comments::clean_markdown( "\xC3\x28" ) );
	}
}
