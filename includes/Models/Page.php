<?php
/**
 * Shop by Therum — Page DTO.
 *
 * Read view of a `pages` row. `tree` is the element JSON the Pure
 * editor saves and the PageRenderer walks.
 */

namespace Shop\Models;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Page {

	public const KIND_PAGE     = 'page';
	public const KIND_TEMPLATE = 'template';
	public const KIND_HEADER   = 'header';
	public const KIND_FOOTER   = 'footer';
	public const KIND_PART     = 'part';

	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly string $slug,
		public readonly string $title,
		public readonly string $kind,
		public readonly ?string $assignedTo,
		public readonly string $status,
		/** @var array<int, array<string,mixed>> root nodes */
		public readonly array $tree,
		/** @var array<string,mixed> */
		public readonly array $meta,
		public readonly ?int $authorId,
		public readonly int $createdAt,
		public readonly int $updatedAt,
		public readonly ?int $publishedAt,
	) {}

	/** @param array<string,mixed> $row */
	public static function fromRow( array $row ): self {
		return new self(
			id:          (int) $row['id'],
			uuid:        (string) $row['uuid'],
			slug:        (string) $row['slug'],
			title:       (string) $row['title'],
			kind:        (string) ( $row['kind'] ?? 'page' ),
			assignedTo:  $row['assigned_to'] ?? null,
			status:      (string) ( $row['status'] ?? 'draft' ),
			tree:        self::decodeArray( $row['tree'] ?? '[]' ),
			meta:        self::decodeArray( $row['meta'] ?? null ),
			authorId:    isset( $row['author_id'] ) ? (int) $row['author_id'] : null,
			createdAt:   (int) ( $row['created_at'] ?? 0 ),
			updatedAt:   (int) ( $row['updated_at'] ?? 0 ),
			publishedAt: isset( $row['published_at'] ) ? (int) $row['published_at'] : null,
		);
	}

	/** @return array<int, array<string,mixed>> */
	private static function decodeArray( ?string $json ): array {
		if ( $json === null || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
