<?php
/**
 * Counter by Therum — admin Categories page.
 *
 * Create / rename / re-parent / delete product categories.
 * Operates on the WordPress `product_cat` taxonomy (the same terms
 * WooCommerce and theme front-ends use), so anything you do here is
 * visible everywhere. No SQLite involvement — categories are taxonomy
 * terms, which is the right place for them.
 *
 * POST handlers run on `admin_post_*` so they have proper nonce + redirect.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CategoriesPage {

	private const TAX = 'product_cat';

	public function register(): void {
		add_action( 'admin_post_counter_category_save',   [ $this, 'handleSave' ] );
		add_action( 'admin_post_counter_category_delete', [ $this, 'handleDelete' ] );
	}

	public function render(): void {
		$terms = taxonomy_exists( self::TAX )
			? get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false ] )
			: [];

		if ( is_wp_error( $terms ) ) $terms = [];

		// Build flat parent dropdown options (id => "indented name").
		$parent_options = [ 0 => __( '— None (top level) —', 'counter' ) ];
		$this->buildParentTree( $terms, 0, 0, $parent_options );

		$flash = get_transient( 'counter_cat_flash' );
		if ( $flash ) delete_transient( 'counter_cat_flash' );
		?>
		<div class="wrap counter-admin">
			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Commerce', 'counter' ); ?>
			</h1>
			<?php SectionTabs::render( 'counter-categories' ); ?>

			<?php if ( $flash ): ?>
				<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $flash['msg'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="counter-admin__split">
				<div class="counter-admin__section counter-admin__section--narrow">
					<header>
						<h2><?php esc_html_e( 'Add new category', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Terms are saved to the WordPress product_cat taxonomy.', 'counter' ); ?></p>
					</header>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="counter-admin__form">
						<input type="hidden" name="action" value="counter_category_save">
						<?php wp_nonce_field( 'counter_category_save' ); ?>

						<div class="counter-admin__row">
							<label for="cat-name"><?php esc_html_e( 'Name', 'counter' ); ?></label>
							<div>
								<input type="text" id="cat-name" name="name" required>
							</div>
						</div>
						<div class="counter-admin__row">
							<label for="cat-slug"><?php esc_html_e( 'Slug', 'counter' ); ?></label>
							<div>
								<input type="text" id="cat-slug" name="slug" placeholder="<?php esc_attr_e( 'Auto from name', 'counter' ); ?>">
								<p class="description"><?php esc_html_e( 'URL handle. Leave blank to generate from the name.', 'counter' ); ?></p>
							</div>
						</div>
						<div class="counter-admin__row">
							<label for="cat-parent"><?php esc_html_e( 'Parent', 'counter' ); ?></label>
							<div>
								<select id="cat-parent" name="parent">
									<?php foreach ( $parent_options as $id => $label ): ?>
										<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="counter-admin__row">
							<label for="cat-desc"><?php esc_html_e( 'Description', 'counter' ); ?></label>
							<div>
								<textarea id="cat-desc" name="description" rows="3"></textarea>
							</div>
						</div>
						<div class="counter-admin__actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Add category', 'counter' ); ?></button>
						</div>
					</form>
				</div>

				<div class="counter-admin__section counter-admin__section--wide">
					<header>
						<h2><?php esc_html_e( 'All categories', 'counter' ); ?></h2>
						<p><?php echo esc_html( sprintf( _n( '%d term', '%d terms', count( $terms ), 'counter' ), count( $terms ) ) ); ?></p>
					</header>
					<?php if ( ! $terms ): ?>
						<p class="counter-admin__empty"><?php esc_html_e( 'No categories yet.', 'counter' ); ?></p>
					<?php else: ?>
						<table class="wp-list-table widefat striped counter-admin__table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'counter' ); ?></th>
									<th><?php esc_html_e( 'Slug', 'counter' ); ?></th>
									<th><?php esc_html_e( 'Products', 'counter' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $terms as $t ): ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $t->name ); ?></strong>
											<?php if ( $t->parent ): ?>
												<div class="counter-admin__sub">↳ child of #<?php echo (int) $t->parent; ?></div>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( $t->slug ); ?></code></td>
										<td><?php echo (int) $t->count; ?></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this category?');" style="display:inline">
												<input type="hidden" name="action" value="counter_category_delete">
												<input type="hidden" name="term_id" value="<?php echo (int) $t->term_id; ?>">
												<?php wp_nonce_field( 'counter_category_delete_' . $t->term_id ); ?>
												<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete', 'counter' ); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
		check_admin_referer( 'counter_category_save' );

		$name = trim( (string) ( $_POST['name'] ?? '' ) );
		if ( $name === '' ) {
			$this->flash( 'error', 'Name is required.' );
			$this->redirect();
		}

		$args = [
			'description' => sanitize_textarea_field( (string) ( $_POST['description'] ?? '' ) ),
			'parent'      => (int) ( $_POST['parent'] ?? 0 ),
		];
		if ( ! empty( $_POST['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $_POST['slug'] );
		}

		$result = wp_insert_term( $name, self::TAX, $args );

		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', 'Category added.' );
		}
		$this->redirect();
	}

	public function handleDelete(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );

		$term_id = (int) ( $_POST['term_id'] ?? 0 );
		check_admin_referer( 'counter_category_delete_' . $term_id );

		$result = wp_delete_term( $term_id, self::TAX );
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$this->flash( 'success', 'Category deleted.' );
		}
		$this->redirect();
	}

	private function flash( string $type, string $msg ): void {
		set_transient( 'counter_cat_flash', [
			'type' => $type === 'error' ? 'error' : 'success',
			'msg'  => $msg,
		], 30 );
	}

	private function redirect(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=counter-categories' ) );
		exit;
	}

	/**
	 * Walk taxonomy terms and append "  — Name" lines to $opts for the
	 * parent dropdown. Recursive over $parent_id matches.
	 */
	private function buildParentTree( array $terms, int $parent_id, int $depth, array &$opts ): void {
		foreach ( $terms as $t ) {
			if ( (int) $t->parent !== $parent_id ) continue;
			$opts[ (int) $t->term_id ] = str_repeat( '— ', $depth + 1 ) . $t->name;
			$this->buildParentTree( $terms, (int) $t->term_id, $depth + 1, $opts );
		}
	}
}
