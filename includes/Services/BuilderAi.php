<?php
/**
 * Shop by Therum — BuilderAi.
 *
 * Translates a natural-language command from the Pure builder's ⌘K
 * palette into a list of tree-mutation ops. The frontend applies the
 * ops to the local tree (so undo/redo still capture them) and the
 * Claude call only ever returns data — never executes anything itself.
 *
 * Op format — all four shapes are intentionally simple so the model
 * doesn't need to know our id scheme to be useful:
 *
 *   { "op": "add",     "type": "heading", "settings": {...},
 *     "parentId": null|string, "index": null|int }
 *   { "op": "update",  "id": "el-xxx",    "settings": {...} }
 *   { "op": "remove",  "id": "el-xxx" }
 *   { "op": "replace", "tree": [...] }       — full rebuild
 *
 * We give the model the catalog of available element types + their
 * controls so it picks a real one. If `parentId`/`index` are null, the
 * client appends at the root.
 *
 * The whole thing is one round-trip through ClaudeClient using forced
 * tool use — we pin the model to a single `apply_ops` tool with a
 * JSON-schema'd `ops` array, then read the tool_use input.
 */

namespace Shop\Services;

use Shop\AI\ClaudeClient;
use Shop\Elements\ElementRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

final class BuilderAi {

	public function __construct(
		private readonly ClaudeClient    $claude,
		private readonly ElementRegistry $elements,
	) {}

	public function available(): bool {
		return ClaudeClient::available();
	}

	/**
	 * @param array<int, array<string,mixed>> $tree   current page tree (read-only)
	 * @param string                          $prompt natural-language instruction
	 *
	 * @return array<int, array<string,mixed>> list of ops (possibly empty)
	 */
	public function commandToOps( array $tree, string $prompt ): array {
		$prompt = trim( $prompt );
		if ( $prompt === '' ) return [];

		$catalog = [];
		foreach ( $this->elements->all() as $el ) {
			$catalog[ $el->id() ] = [
				'name'     => $el->name(),
				'category' => $el->category(),
				'controls' => array_map(
					static fn( $c ) => [
						'id'      => $c['id']      ?? '',
						'type'    => $c['type']    ?? 'text',
						'default' => $c['default'] ?? null,
					],
					$el->controls(),
				),
			];
		}

		$system =
			"You edit a Pure page tree for an e-commerce site builder. The user " .
			"will describe a change in plain English. Reply by calling the " .
			"apply_ops tool with a list of ops. Use only element types that " .
			"appear in the catalog. Keep ops minimal — prefer `add` / `update` " .
			"/ `remove` over `replace` unless the user explicitly asks for a " .
			"full rebuild. When adding, leave `parentId` and `index` null to " .
			"append at the root. Settings must be valid JSON objects keyed by " .
			"control id; omit settings the user didn't mention.";

		$user = [
			'role'    => 'user',
			'content' => [
				[ 'type' => 'text', 'text' =>
					"## Element catalog (id → spec)\n" .
					wp_json_encode( $catalog, JSON_UNESCAPED_SLASHES ) . "\n\n" .
					"## Current tree\n" .
					wp_json_encode( $tree, JSON_UNESCAPED_SLASHES ) . "\n\n" .
					"## User command\n" . $prompt
				],
			],
		];

		$tool = [
			'name'         => 'apply_ops',
			'description'  => 'Apply a list of mutation ops to the page tree.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'ops' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'op'       => [ 'type' => 'string', 'enum' => [ 'add', 'update', 'remove', 'replace' ] ],
								'type'     => [ 'type' => 'string' ],
								'settings' => [ 'type' => 'object' ],
								'parentId' => [ 'type' => [ 'string', 'null' ] ],
								'index'    => [ 'type' => [ 'integer', 'null' ] ],
								'id'       => [ 'type' => 'string' ],
								'tree'     => [ 'type' => 'array' ],
							],
							'required' => [ 'op' ],
						],
					],
				],
				'required'   => [ 'ops' ],
			],
		];

		$res = $this->claude->complete(
			$system,
			$user['content'],
			[ $tool ],
			2048,
		);

		// Pull the first tool_use block — same pattern as the importers.
		foreach ( $res['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'tool_use' && ( $block['name'] ?? '' ) === 'apply_ops' ) {
				$input = $block['input'] ?? [];
				return is_array( $input['ops'] ?? null ) ? $input['ops'] : [];
			}
		}
		return [];
	}
}
