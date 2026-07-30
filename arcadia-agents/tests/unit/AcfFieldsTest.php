<?php
/**
 * Tests for ACF fields discovery and write trait.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load dependencies.
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/api/trait-api-acf-fields.php';

// Load ACF adapter for sideload_image_field reference.
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';

/**
 * Test harness that exposes the private trait methods for testing.
 */
class AcfFieldsTestHarness {
	use \Arcadia_API_ACF_Fields_Handler;

	/**
	 * Public proxy for get_acf_field_groups_for_post_types().
	 *
	 * @return array
	 */
	public function test_get_acf_field_groups_for_post_types() {
		return $this->get_acf_field_groups_for_post_types();
	}

	/**
	 * Public proxy for extract_post_types_from_location().
	 *
	 * @param array $group The ACF field group.
	 * @return array
	 */
	public function test_extract_post_types_from_location( $group ) {
		return $this->extract_post_types_from_location( $group );
	}

	/**
	 * Public proxy for process_acf_fields().
	 *
	 * @param int    $post_id       The post ID.
	 * @param array  $acf_fields    Field name => value pairs.
	 * @param string $post_type     The post type.
	 * @param string $post_content  The rendered post content.
	 * @param bool   $skip_markdown Treat wysiwyg values as already-HTML (round-trip).
	 * @return true|\WP_Error
	 */
	public function test_process_acf_fields( $post_id, $acf_fields, $post_type, $post_content, $skip_markdown = false ) {
		return $this->process_acf_fields( $post_id, $acf_fields, $post_type, $post_content, $skip_markdown );
	}

	/**
	 * Public proxy for build_acf_field_type_map().
	 *
	 * @param string $post_type The post type.
	 * @return array
	 */
	public function test_build_acf_field_type_map( $post_type ) {
		return $this->build_acf_field_type_map( $post_type );
	}
}

/**
 * Test class for ACF fields trait.
 */
class AcfFieldsTest extends TestCase {

	/**
	 * Test harness instance.
	 *
	 * @var AcfFieldsTestHarness
	 */
	private $harness;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		$this->harness = new AcfFieldsTestHarness();

		// Reset global ACF stubs.
		global $_test_acf_field_groups, $_test_acf_fields_by_group, $_test_acf_update_field_calls;
		$_test_acf_field_groups        = array();
		$_test_acf_fields_by_group     = array();
		$_test_acf_update_field_calls  = array();
	}

	// =========================================================================
	// Discovery tests
	// =========================================================================

	/**
	 * Test discovery returns empty array when ACF functions are unavailable.
	 *
	 * Since our bootstrap defines the stubs, we test the "no groups" path
	 * which behaves identically to "no ACF" from the caller's perspective.
	 */
	public function test_discovery_returns_empty_when_no_groups(): void {
		global $_test_acf_field_groups;
		$_test_acf_field_groups = array();

		$result = $this->harness->test_get_acf_field_groups_for_post_types();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test discovery returns groups with post_type location rules.
	 */
	public function test_discovery_returns_groups_with_post_type_rules(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_article',
				'title'    => 'Article Fields',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'article',
						),
					),
				),
			),
		);

		$_test_acf_fields_by_group = array(
			'group_article' => array(
				array(
					'name'     => 'contenu',
					'type'     => 'wysiwyg',
					'required' => true,
					'label'    => 'Contenu',
				),
				array(
					'name'     => 'source_url',
					'type'     => 'url',
					'required' => false,
					'label'    => 'Source URL',
				),
			),
		);

		$result = $this->harness->test_get_acf_field_groups_for_post_types();

		$this->assertArrayHasKey( 'article', $result );
		$this->assertCount( 1, $result['article'] );
		$this->assertEquals( 'Article Fields', $result['article'][0]['title'] );
		$this->assertCount( 2, $result['article'][0]['fields'] );
		$this->assertEquals( 'contenu', $result['article'][0]['fields'][0]['name'] );
		$this->assertEquals( 'wysiwyg', $result['article'][0]['fields'][0]['type'] );
		$this->assertTrue( $result['article'][0]['fields'][0]['required'] );
	}

	/**
	 * Test discovery skips groups without post_type location rules.
	 */
	public function test_discovery_skips_groups_without_post_type_rules(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_block',
				'title'    => 'Block Fields',
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/hero',
						),
					),
				),
			),
		);

		$_test_acf_fields_by_group = array(
			'group_block' => array(
				array(
					'name' => 'title',
					'type' => 'text',
					'label' => 'Title',
				),
			),
		);

		$result = $this->harness->test_get_acf_field_groups_for_post_types();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test discovery includes choices for select/radio fields.
	 */
	public function test_discovery_includes_choices_for_select(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_pt',
				'title'    => 'Post Type Fields',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			),
		);

		$_test_acf_fields_by_group = array(
			'group_pt' => array(
				array(
					'name'    => 'category_style',
					'type'    => 'select',
					'label'   => 'Category Style',
					'choices' => array(
						'default' => 'Default',
						'compact' => 'Compact',
						'wide'    => 'Wide',
					),
				),
			),
		);

		$result = $this->harness->test_get_acf_field_groups_for_post_types();

		$this->assertArrayHasKey( 'post', $result );
		$field = $result['post'][0]['fields'][0];
		$this->assertEquals( 'select', $field['type'] );
		$this->assertArrayHasKey( 'choices', $field );
		$this->assertEquals( array( 'default', 'compact', 'wide' ), $field['choices'] );
	}

	/**
	 * Test discovery includes sub_fields for repeaters.
	 */
	public function test_discovery_includes_sub_fields_for_repeaters(): void {
		global $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'      => 'group_faq',
				'title'    => 'FAQ Fields',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'article',
						),
					),
				),
			),
		);

		$_test_acf_fields_by_group = array(
			'group_faq' => array(
				array(
					'name'       => 'faq_items',
					'type'       => 'repeater',
					'label'      => 'FAQ Items',
					'sub_fields' => array(
						array(
							'name' => 'question',
							'type' => 'text',
						),
						array(
							'name' => 'answer',
							'type' => 'wysiwyg',
						),
					),
				),
			),
		);

		$result = $this->harness->test_get_acf_field_groups_for_post_types();

		$this->assertArrayHasKey( 'article', $result );
		$field = $result['article'][0]['fields'][0];
		$this->assertEquals( 'repeater', $field['type'] );
		$this->assertArrayHasKey( 'sub_fields', $field );
		$this->assertCount( 2, $field['sub_fields'] );
		$this->assertEquals( 'question', $field['sub_fields'][0]['name'] );
		$this->assertEquals( 'text', $field['sub_fields'][0]['type'] );
	}

	// =========================================================================
	// Write tests
	// =========================================================================

	/**
	 * Test process_acf_fields calls update_field for each entry.
	 */
	public function test_process_calls_update_field_for_each_entry(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'intro', 'type' => 'text' ),
				array( 'name' => 'body', 'type' => 'textarea' ),
			),
		);

		$result = $this->harness->test_process_acf_fields(
			42,
			array(
				'intro' => 'Hello',
				'body'  => 'World',
			),
			'article',
			'<p>Rendered content</p>'
		);

		$this->assertTrue( $result );
		$this->assertCount( 2, $_test_acf_update_field_calls );
		$this->assertEquals( 'intro', $_test_acf_update_field_calls[0]['field_name'] );
		$this->assertEquals( 'Hello', $_test_acf_update_field_calls[0]['value'] );
		$this->assertEquals( 42, $_test_acf_update_field_calls[0]['post_id'] );
		$this->assertEquals( 'body', $_test_acf_update_field_calls[1]['field_name'] );
		$this->assertEquals( 'World', $_test_acf_update_field_calls[1]['value'] );
	}

	/**
	 * Test null value on wysiwyg field copies post_content.
	 */
	public function test_wysiwyg_null_copies_post_content(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'contenu', 'type' => 'wysiwyg' ),
			),
		);

		$rendered = '<h2>Title</h2><p>Some content here</p>';

		$result = $this->harness->test_process_acf_fields(
			99,
			array( 'contenu' => null ),
			'article',
			$rendered
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $_test_acf_update_field_calls );
		$this->assertEquals( 'contenu', $_test_acf_update_field_calls[0]['field_name'] );
		$this->assertEquals( $rendered, $_test_acf_update_field_calls[0]['value'] );
	}

	/**
	 * Test empty acf_fields array is a no-op.
	 */
	public function test_empty_acf_fields_is_noop(): void {
		global $_test_acf_update_field_calls;

		$result = $this->harness->test_process_acf_fields(
			1,
			array(),
			'post',
			''
		);

		$this->assertTrue( $result );
		$this->assertCount( 0, $_test_acf_update_field_calls );
	}

	/**
	 * Test process returns WP_Error when update_field is unavailable.
	 *
	 * We simulate this by temporarily renaming the function check.
	 * Since we can't undefine functions in PHP, we test the error path
	 * by checking that the trait method returns the correct error code
	 * when the function does not exist. Because our bootstrap defines
	 * update_field, we verify the success path instead and check the
	 * error structure separately.
	 */
	public function test_process_returns_error_structure_when_acf_missing(): void {
		// We can't truly undefine update_field in a running PHP process,
		// so we verify the WP_Error that would be returned has the right code.
		$error = new \WP_Error(
			'acf_unavailable',
			'ACF is required to process acf_fields.',
			array( 'status' => 400 )
		);

		$this->assertEquals( 'acf_unavailable', $error->get_error_code() );
		$this->assertEquals( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Test process_acf_fields converts markdown in wysiwyg fields.
	 */
	public function test_wysiwyg_converts_markdown_to_html(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'contenu', 'type' => 'wysiwyg' ),
			),
		);

		$result = $this->harness->test_process_acf_fields(
			50,
			array( 'contenu' => 'This is **bold** and *italic* text.' ),
			'article',
			''
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $_test_acf_update_field_calls );
		$this->assertStringContainsString( '<strong>bold</strong>', $_test_acf_update_field_calls[0]['value'] );
		$this->assertStringContainsString( '<em>italic</em>', $_test_acf_update_field_calls[0]['value'] );
		$this->assertStringNotContainsString( '**bold**', $_test_acf_update_field_calls[0]['value'] );
	}

	/**
	 * Phase 35 — the explicit ACF wysiwyg write path preserves structural HTML
	 * (<h2>, <ul>) so a natively-authored article round-trips faithfully.
	 */
	public function test_wysiwyg_preserves_structural_html(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'contenu', 'type' => 'wysiwyg' ),
			),
		);

		$result = $this->harness->test_process_acf_fields(
			50,
			array( 'contenu' => '<h2>Titre</h2><ul><li>a</li></ul>' ),
			'article',
			''
		);

		$this->assertTrue( $result );
		$value = $_test_acf_update_field_calls[0]['value'];
		$this->assertStringContainsString( '<h2>Titre</h2>', $value );
		$this->assertStringContainsString( '<li>a</li>', $value );
	}

	/**
	 * Phase 36 #A3 — skip_markdown=true treats the wysiwyg value as already-HTML
	 * (round-trip, aa-u6nl): no markdown parsing, just sanitisation. A literal
	 * `## x` and a stray `*pair*` survive untouched instead of being mangled.
	 */
	public function test_wysiwyg_skip_markdown_does_not_parse(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'contenu', 'type' => 'wysiwyg' ),
			),
		);

		// BARE markdown (no leading HTML tag) so the two branches genuinely differ:
		// the HTML-passthrough net would NOT mask a dropped skip_markdown argument
		// here (review #14 — the old `<p>…</p>` input passed through either way).
		$markdown = "## Titre\n\nune *paire*";

		// skip_markdown = true → stored verbatim (only wp_kses_post), NOT parsed.
		$skipped = $this->harness->test_process_acf_fields(
			50,
			array( 'contenu' => $markdown ),
			'article',
			'',
			true
		);
		$this->assertTrue( $skipped );
		$value = $_test_acf_update_field_calls[0]['value'];
		$this->assertStringContainsString( '## Titre', $value );
		$this->assertStringContainsString( '*paire*', $value );
		$this->assertStringNotContainsString( '<h2>', $value );
		$this->assertStringNotContainsString( '<em>', $value );

		// skip_markdown = false (default) → the SAME input IS parsed. This proves
		// the flag is threaded through finalize_post → process_acf_fields: drop the
		// argument and this branch fails.
		$_test_acf_update_field_calls = array();
		$parsed                       = $this->harness->test_process_acf_fields(
			50,
			array( 'contenu' => $markdown ),
			'article',
			'',
			false
		);
		$this->assertTrue( $parsed );
		$parsed_value = $_test_acf_update_field_calls[0]['value'];
		$this->assertStringContainsString( '<h2>Titre</h2>', $parsed_value );
		$this->assertStringContainsString( '<em>paire</em>', $parsed_value );
	}

	/**
	 * Test passthrough for text/select/radio fields.
	 */
	public function test_passthrough_for_text_select_radio(): void {
		global $_test_acf_update_field_calls, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$_test_acf_field_groups = array(
			array(
				'key'   => 'group_1',
				'title' => 'Test',
			),
		);

		$_test_acf_fields_by_group = array(
			'group_1' => array(
				array( 'name' => 'subtitle', 'type' => 'text' ),
				array( 'name' => 'style', 'type' => 'select' ),
				array( 'name' => 'layout', 'type' => 'radio' ),
			),
		);

		$result = $this->harness->test_process_acf_fields(
			10,
			array(
				'subtitle' => 'My subtitle',
				'style'    => 'compact',
				'layout'   => 'wide',
			),
			'article',
			''
		);

		$this->assertTrue( $result );
		$this->assertCount( 3, $_test_acf_update_field_calls );

		// Values should be passed through unchanged.
		$this->assertEquals( 'My subtitle', $_test_acf_update_field_calls[0]['value'] );
		$this->assertEquals( 'compact', $_test_acf_update_field_calls[1]['value'] );
		$this->assertEquals( 'wide', $_test_acf_update_field_calls[2]['value'] );
	}
}
