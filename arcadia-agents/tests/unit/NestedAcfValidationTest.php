<?php
/**
 * Tests: an ACF block is validated identically wherever it sits in the tree (Phase 48).
 *
 * AA hit a PHP fatal on preprod-iselection (plugin 0.7.0, post 76068, block
 * `acf/lp-sticky-menu` carrying a flat-encoded repeater). The SAME payload was
 * accepted at the root and killed the REST request once nested. Their bisection
 * isolated the repeater COUNTER on the nested path: drop `links` from the
 * properties and nesting passes; put it back and it is fatal.
 *
 * Root cause: Arcadia_ACF_Validator::validate_block_recursive() only recursed
 * into `children`, ignoring `inner_blocks` / `innerBlocks` — the very keys a
 * round-trip payload carries. A nested acf/* block therefore never reached
 * validate_acf_block(), so none of the three root-path operations ran on it:
 * flat-repeater expansion, canonical coercion, type checks. `links` stayed the
 * integer 3 and reached Arcadia_ACF_Adapter::flatten_repeater(), whose
 * `count( $rows )` is a TypeError on an int in PHP 8.
 *
 * The contract these tests pin is the one AA asked for, and it is stronger than
 * "no more fatal": a payload refused with 422 at the root must be refused with
 * 422 nested, and a payload accepted at the root must be accepted nested —
 * recursively, at any depth. A fix that merely stopped the fatal by rejecting
 * everything nested would satisfy "no fatal" and break them just as badly.
 *
 * @package ArcadiaAgents\Tests
 */

namespace ArcadiaAgents\Tests;

use PHPUnit\Framework\TestCase;

// Load required classes in dependency order.
require_once dirname( __DIR__, 2 ) . '/includes/adapters/interface-block-adapter.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-gutenberg.php';
require_once dirname( __DIR__, 2 ) . '/includes/adapters/class-adapter-acf.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-block-processor.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-blocks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-coercer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-repeater-handler.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf-validator.php';

/**
 * Test class for nested ACF block validation symmetry.
 */
class NestedAcfValidationTest extends TestCase {

	/**
	 * Reset every singleton and stub the pipeline touches.
	 */
	protected function setUp(): void {
		foreach ( array( 'Arcadia_Block_Registry', 'Arcadia_ACF_Validator', 'Arcadia_Blocks' ) as $class ) {
			$ref  = new \ReflectionClass( $class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;
		$_test_acf_block_types     = array();
		$_test_acf_field_groups    = array();
		$_test_acf_fields_by_group = array();

		$this->register_sticky_menu();
		$this->register_container( 'acf/lp-group' );
	}

	// =========================================================================
	// Fixtures — the real iSelection shapes
	// =========================================================================

	/**
	 * Register `acf/lp-sticky-menu`: a `title` text plus a `links` repeater.
	 */
	private function register_sticky_menu() {
		$this->register_acf_block(
			'acf/lp-sticky-menu',
			array(
				array(
					'name'     => 'title',
					'type'     => 'text',
					'label'    => 'Title',
					'key'      => 'field_sticky_title',
					'required' => false,
				),
				array(
					'name'       => 'links',
					'type'       => 'repeater',
					'label'      => 'Links',
					'key'        => 'field_sticky_links',
					'required'   => false,
					'sub_fields' => array(
						array(
							'name'  => 'link',
							'type'  => 'text',
							'label' => 'Link',
							'key'   => 'field_sticky_link',
						),
					),
				),
			)
		);
	}

	/**
	 * Register a container ACF block with no fields of its own.
	 *
	 * @param string $name Block name.
	 */
	private function register_container( $name ) {
		$this->register_acf_block(
			$name,
			array(
				array(
					'name'     => 'block-id',
					'type'     => 'text',
					'label'    => 'Block ID',
					'key'      => 'field_group_block_id',
					'required' => false,
				),
			)
		);
	}

	/**
	 * Register an ACF block with fields in the test stubs.
	 *
	 * Mirrors AcfValidatorTest::register_acf_block() — block-only location rule,
	 * so the block is available for every post type.
	 *
	 * @param string $block_name Full block name.
	 * @param array  $fields     ACF field descriptors.
	 */
	private function register_acf_block( $block_name, $fields ) {
		global $_test_acf_block_types, $_test_acf_field_groups, $_test_acf_fields_by_group;

		$short = preg_replace( '/^acf\//', '', $block_name );

		$_test_acf_block_types[ $block_name ] = array( 'title' => $short );

		$group_key = 'group_' . $short;

		$_test_acf_field_groups[] = array(
			'key'      => $group_key,
			'title'    => $short,
			'location' => array(
				array(
					array(
						'param'    => 'block',
						'operator' => '==',
						'value'    => $block_name,
					),
				),
			),
		);

		$_test_acf_fields_by_group[ $group_key ] = $fields;
	}

	/**
	 * The sticky-menu block, with the properties variant under test.
	 *
	 * @param array $properties Block properties.
	 * @return array Block node.
	 */
	private function sticky_menu( array $properties ) {
		return array(
			'type'       => 'acf/lp-sticky-menu',
			'properties' => $properties,
		);
	}

	/**
	 * The three property variants of AA's bisection.
	 *
	 * @return array<string, array> Variant name → properties.
	 */
	private function property_variants() {
		return array(
			// The row AA proved is a clean 422 at the root: a counter announcing
			// three rows with not one leaf to back it.
			'counter without leaves' => array(
				'title' => 'Sommaire',
				'links' => 3,
			),
			// The row that is accepted at the root (revision 93541) and was fatal
			// nested. This is the payload of a real iSelection landing.
			'counter with leaves'    => array(
				'title'        => 'Sommaire',
				'links'        => 3,
				'links_0_link' => '#dispositif',
				'links_1_link' => '#simulateur',
				'links_2_link' => '#contact',
			),
			// Leaves with no counter: nothing to expand, nothing to flatten.
			'leaves without counter' => array(
				'title'        => 'Sommaire',
				'links_0_link' => '#dispositif',
			),
		);
	}

	/**
	 * Wrap a block at each of the positions AA probed.
	 *
	 * Each entry returns the full `$json` tree plus the path at which the block
	 * under test can be read back out of it after validation (mutations happen
	 * in place, and reading them back is how we prove the recursion is by
	 * reference rather than by copy).
	 *
	 * @return array<string, callable> Position name → fn(array $block): array{json: array, read: callable}
	 */
	private function positions() {
		return array(
			'root'                       => function ( $block ) {
				return array(
					'json' => array( 'children' => array( $block ) ),
					'read' => function ( $json ) {
						return $json['children'][0];
					},
				);
			},
			'nested in acf/lp-group'     => function ( $block ) {
				return array(
					'json' => array(
						'children' => array(
							array(
								'type'          => 'acf/lp-group',
								'properties'    => array( 'block-id' => 'grp-1' ),
								'inner_content' => array( '<div class="grp">', null, '</div>' ),
								'inner_blocks'  => array( $block ),
							),
						),
					),
					'read' => function ( $json ) {
						return $json['children'][0]['inner_blocks'][0];
					},
				);
			},
			'nested in core/group'       => function ( $block ) {
				return array(
					'json' => array(
						'children' => array(
							array(
								'type'          => 'core/group',
								'inner_content' => array( '<div class="wp-block-group">', null, '</div>' ),
								'inner_blocks'  => array( $block ),
							),
						),
					),
					'read' => function ( $json ) {
						return $json['children'][0]['inner_blocks'][0];
					},
				);
			},
			'nested under innerBlocks'   => function ( $block ) {
				return array(
					'json' => array(
						'children' => array(
							array(
								'type'         => 'core/group',
								'innerBlocks'  => array( $block ),
							),
						),
					),
					'read' => function ( $json ) {
						return $json['children'][0]['innerBlocks'][0];
					},
				);
			},
			// Depth two: AA's requirement is "recursively, at any depth".
			'nested two levels deep'     => function ( $block ) {
				return array(
					'json' => array(
						'children' => array(
							array(
								'type'         => 'core/group',
								'inner_blocks' => array(
									array(
										'type'         => 'acf/lp-group',
										'properties'   => array( 'block-id' => 'grp-2' ),
										'inner_blocks' => array( $block ),
									),
								),
							),
						),
					),
					'read' => function ( $json ) {
						return $json['children'][0]['inner_blocks'][0]['inner_blocks'][0];
					},
				);
			},
		);
	}

	/**
	 * Run the validator over a tree and return [result, mutated tree].
	 *
	 * @param array $json Block tree.
	 * @return array{0: true|\WP_Error, 1: array}
	 */
	private function validate( array $json ) {
		$validator = \Arcadia_ACF_Validator::get_instance();
		$result    = $validator->validate_and_preprocess( $json, 'landing' );
		return array( $result, $json );
	}

	// =========================================================================
	// The matrix — same verdict at every position
	// =========================================================================

	/**
	 * Test: every property variant gets the SAME verdict at every position.
	 *
	 * This is the assertion that carries the phase. It is deliberately written
	 * against the root verdict rather than against hard-coded expectations, so
	 * it keeps holding if the root policy itself ever changes: what is pinned is
	 * the symmetry, which is what AA asked for.
	 */
	public function test_same_verdict_at_every_position(): void {
		foreach ( $this->property_variants() as $variant => $properties ) {
			$positions = $this->positions();

			// The root verdict is the reference every nested position must match.
			$at_root                = $positions['root']( $this->sticky_menu( $properties ) );
			list( $root_result )    = $this->validate( $at_root['json'] );
			$root_verdict           = $this->verdict_of( $root_result );

			foreach ( $positions as $position => $wrap ) {
				if ( 'root' === $position ) {
					continue;
				}

				$this->setUp(); // Fresh singletons + stubs per probe.

				$wrapped            = $wrap( $this->sticky_menu( $properties ) );
				list( $result )     = $this->validate( $wrapped['json'] );

				$this->assertSame(
					$root_verdict,
					$this->verdict_of( $result ),
					sprintf(
						'Variant "%s" is judged differently at the root and %s. '
						. 'Root said %s, %s said %s.',
						$variant,
						$position,
						wp_json_encode( $root_verdict ),
						$position,
						wp_json_encode( $this->verdict_of( $result ) )
					)
				);
			}
		}
	}

	/**
	 * Reduce a validator result to a comparable verdict.
	 *
	 * Errors are reduced to (field, expected, got) triples: block_index and the
	 * new block_path legitimately differ between positions, and comparing them
	 * would make the symmetry assertion impossible to satisfy.
	 *
	 * @param true|\WP_Error $result Validator result.
	 * @return array Comparable verdict.
	 */
	private function verdict_of( $result ) {
		if ( true === $result ) {
			return array( 'valid' => true );
		}

		$errors  = $result->get_error_data()['errors'] ?? array();
		$reduced = array();
		foreach ( $errors as $error ) {
			$reduced[] = array(
				'block_type' => $error['block_type'],
				'field'      => $error['field'],
				'expected'   => $error['expected'],
				'got'        => $error['got'],
			);
		}
		sort( $reduced );

		return array(
			'valid'  => false,
			'errors' => $reduced,
		);
	}

	/**
	 * Test: the counter-without-leaves variant is a 422 nested, not a fatal.
	 *
	 * Spelled out separately from the symmetry test because this is the exact
	 * error AA gets at the root and must also get nested — naming the offending
	 * field. A symmetry that held by being silently valid everywhere would pass
	 * the test above and still leave them without a diagnosis.
	 */
	public function test_nested_counter_without_leaves_is_a_named_422(): void {
		$positions = $this->positions();
		$wrapped   = $positions['nested in acf/lp-group']( $this->sticky_menu( array( 'links' => 3 ) ) );

		list( $result ) = $this->validate( $wrapped['json'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'acf_validation_failed', $result->get_error_code() );

		$errors = $result->get_error_data()['errors'];
		$this->assertCount( 1, $errors );
		$this->assertSame( 'acf/lp-sticky-menu', $errors[0]['block_type'] );
		$this->assertSame( 'links', $errors[0]['field'] );
		$this->assertSame( 'array', $errors[0]['expected'] );
		$this->assertSame( 'integer', $errors[0]['got'] );
	}

	// =========================================================================
	// The mutation must land on the tree the renderer will walk
	// =========================================================================

	/**
	 * Test: the nested block's flat repeater is expanded IN PLACE.
	 *
	 * The validator's whole effect on a valid payload is a mutation:
	 * expand_flat_repeaters() rewrites `links: 3` + leaves into an array of
	 * rows, and process_block() renders THAT tree afterwards. A recursion that
	 * walked children by copy would close the 422 and leave the fatal exactly
	 * where it was — which is why this is asserted separately from the verdict.
	 */
	public function test_nested_flat_repeater_is_expanded_in_place(): void {
		$positions = $this->positions();
		$variants  = $this->property_variants();
		$wrapped   = $positions['nested in acf/lp-group']( $this->sticky_menu( $variants['counter with leaves'] ) );

		list( $result, $json ) = $this->validate( $wrapped['json'] );

		$this->assertTrue( $result );

		$block = $wrapped['read']( $json );

		$this->assertIsArray(
			$block['properties']['links'],
			'The nested repeater was not expanded: the renderer will still receive an integer.'
		);
		$this->assertSame(
			array(
				array( 'link' => '#dispositif' ),
				array( 'link' => '#simulateur' ),
				array( 'link' => '#contact' ),
			),
			$block['properties']['links']
		);
		$this->assertArrayNotHasKey( 'links_0_link', $block['properties'], 'Consumed flat keys must be removed.' );
	}

	// =========================================================================
	// The fatal itself
	// =========================================================================

	/**
	 * Test: rendering the nested payload does not fatal, and keeps the rows.
	 *
	 * End-to-end over the pipeline AA actually calls: json_to_blocks() runs both
	 * validators then the renderer. Before Phase 48 this raised a TypeError out
	 * of flatten_repeater()'s count() and the REST request died with an HTML
	 * "critical error" page carrying no code, no message and no field name.
	 */
	public function test_nested_repeater_renders_without_fatal(): void {
		$positions = $this->positions();
		$variants  = $this->property_variants();
		$wrapped   = $positions['nested in acf/lp-group']( $this->sticky_menu( $variants['counter with leaves'] ) );

		$blocks = \Arcadia_Blocks::get_instance();
		$output = $blocks->json_to_blocks( $wrapped['json'], 'landing' );

		$this->assertIsString( $output, 'json_to_blocks returned a WP_Error instead of markup: ' . ( is_wp_error( $output ) ? $output->get_error_message() : '' ) );
		$this->assertStringContainsString( 'acf/lp-sticky-menu', $output );
		// Flat ACF storage: the counter plus one key per row.
		$this->assertStringContainsString( '#dispositif', $output );
		$this->assertStringContainsString( '#simulateur', $output );
		$this->assertStringContainsString( '#contact', $output );
	}

	/**
	 * Test: a repeater counter that survives to the renderer never fatals.
	 *
	 * Belt and braces behind the validator: with Phase 48.1 in place this input
	 * cannot reach the adapter, so the guard is here for the NEXT gap rather
	 * than this one. Rendering degrades to an empty repeater; it does not throw.
	 */
	public function test_adapter_never_fatals_on_a_non_array_repeater(): void {
		$adapter = new \Arcadia_ACF_Adapter();

		$output = $adapter->custom_block(
			'acf/lp-sticky-menu',
			array(
				'title' => 'Sommaire',
				'links' => 3,
			)
		);

		$this->assertIsString( $output );
		$this->assertStringContainsString( 'acf/lp-sticky-menu', $output );
	}

	// =========================================================================
	// Non-regression: the round-trip exemption still protects third-party leaves
	// =========================================================================

	/**
	 * Test: an unregistered third-party leaf nested in a container still passes.
	 *
	 * This is the case review #5 (Phase 38) opened the exemption for: a block
	 * belonging to the site, not to the agent, which the renderer preserves as
	 * native markup. Closing the nested validation gap must not close this.
	 */
	public function test_unregistered_third_party_leaf_is_still_accepted_nested(): void {
		$json = array(
			'children' => array(
				array(
					'type'          => 'core/group',
					'inner_content' => array( '<div>', null, '</div>' ),
					'inner_blocks'  => array(
						array(
							'type'       => 'acme/spacer',
							'properties' => array( 'height' => 40 ),
						),
					),
				),
			),
		);

		$blocks = \Arcadia_Blocks::get_instance();
		$output = $blocks->json_to_blocks( $json, 'landing' );

		$this->assertIsString(
			$output,
			'A third-party leaf the site owns must not 422 inside a round-trip container.'
		);
		$this->assertStringContainsString( 'acme/spacer', $output );
	}

	/**
	 * Test: a registered NON-acf block is required-field checked when nested.
	 *
	 * This isolates Phase 48.4, on the second validator
	 * (Arcadia_Blocks::validate_block_recursive), which used to let every
	 * namespaced child of a container skip the registry checks by position
	 * alone. A block type outside the `acf/` namespace is the case that pins it:
	 * Arcadia_ACF_Validator only ever looks at `acf/*`, so it cannot mask the
	 * regression here the way it does on an ACF block. This is the shape a
	 * non-ACF site produces — discover_gutenberg_blocks() registers theme and
	 * plugin blocks under their own namespaces.
	 *
	 * Written after a mutation pass: the first version of this test used an
	 * `acf/*` block and stayed green with 48.4 reverted, because 48.1 had
	 * already closed the same hole through the other validator.
	 */
	public function test_nested_registered_non_acf_block_gets_required_field_checks(): void {
		$this->register_acf_block(
			'mytheme/card',
			array(
				array(
					'name'     => 'heading',
					'type'     => 'text',
					'label'    => 'Heading',
					'key'      => 'field_card_heading',
					'required' => true,
				),
			)
		);

		$nested = array(
			'children' => array(
				array(
					'type'          => 'core/group',
					'inner_content' => array( '<div>', null, '</div>' ),
					'inner_blocks'  => array(
						array(
							'type'       => 'mytheme/card',
							'properties' => array( 'subtitle' => 'no heading here' ),
						),
					),
				),
			),
		);

		$blocks = \Arcadia_Blocks::get_instance();
		$result = $blocks->json_to_blocks( $nested, 'landing' );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A registered block missing a required field must 422 nested, as it does at the root.'
		);
		$this->assertSame( 'missing_required_field', $result->get_error_code() );
	}

	/**
	 * Test: a nested ACF block missing a REQUIRED field is refused like at root.
	 *
	 * End-to-end contract on the shape AA actually sends. Does not isolate a
	 * single guard — both validators cover it now, which is the point.
	 */
	public function test_nested_registered_block_still_gets_required_field_checks(): void {
		$this->register_acf_block(
			'acf/lp-cta',
			array(
				array(
					'name'     => 'label',
					'type'     => 'text',
					'label'    => 'Label',
					'key'      => 'field_cta_label',
					'required' => true,
				),
			)
		);

		$json = array(
			'children' => array(
				array(
					'type'         => 'acf/lp-group',
					'properties'   => array( 'block-id' => 'grp-1' ),
					'inner_blocks' => array(
						array(
							'type'       => 'acf/lp-cta',
							'properties' => array( 'block-id' => 'x' ),
						),
					),
				),
			),
		);

		$blocks = \Arcadia_Blocks::get_instance();
		$result = $blocks->json_to_blocks( $json, 'landing' );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'A registered ACF block missing a required field must 422 nested, as it does at the root.'
		);
	}

	// =========================================================================
	// Error path readability
	// =========================================================================

	/**
	 * Test: a nested error names its position in the tree.
	 *
	 * `block_index` is the index within the PARENT, so on a nested block
	 * "block[0]" can mean three different blocks in one payload — AA had no way
	 * to tell which. block_path is additive; block_index is unchanged.
	 */
	public function test_nested_error_carries_a_block_path(): void {
		$json = array(
			'children' => array(
				array( 'type' => 'core/paragraph', 'content' => 'filler' ),
				array(
					'type'         => 'acf/lp-group',
					'properties'   => array( 'block-id' => 'grp-1' ),
					'inner_blocks' => array(
						$this->sticky_menu( array( 'links' => 3 ) ),
					),
				),
			),
		);

		list( $result ) = $this->validate( $json );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$errors = $result->get_error_data()['errors'];

		$this->assertArrayHasKey( 'block_path', $errors[0] );
		$this->assertSame( 'children[1].inner_blocks[0]', $errors[0]['block_path'] );
		$this->assertSame( 0, $errors[0]['block_index'], 'block_index stays the index within the parent.' );
	}
}
