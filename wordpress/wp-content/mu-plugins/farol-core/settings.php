<?php
/**
 * Página "Configurações → O Farol", com abas. Cada parte do app registra a
 * sua pelo filtro `farol_settings_tabs`:
 *
 *     add_filter( 'farol_settings_tabs', fn( $tabs ) => $tabs + array(
 *         'minha-aba' => array( 'label' => 'Minha aba', 'order' => 30, 'render' => 'minha_funcao' ),
 *     ) );
 *
 * @package OFarol
 */

defined( 'ABSPATH' ) || exit;

/** Abas registradas, já ordenadas. */
function farol_settings_tabs(): array {
	$tabs = (array) apply_filters( 'farol_settings_tabs', array() );
	uasort( $tabs, fn( $a, $b ) => ( $a['order'] ?? 50 ) <=> ( $b['order'] ?? 50 ) );
	return $tabs;
}

function farol_settings_url( string $tab = '' ): string {
	return add_query_arg( array_filter( array( 'page' => 'farol', 'tab' => $tab ) ), admin_url( 'options-general.php' ) );
}

add_action(
	'admin_menu',
	function () {
		add_options_page( 'O Farol', 'O Farol', 'manage_options', 'farol', 'farol_render_settings_page' );
	}
);

function farol_render_settings_page(): void {
	$tabs    = farol_settings_tabs();
	$current = sanitize_key( $_GET['tab'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $tabs[ $current ] ) ) {
		$current = (string) array_key_first( $tabs );
	}
	?>
	<div class="wrap">
		<h1>O Farol</h1>
		<nav class="nav-tab-wrapper" aria-label="Seções">
			<?php foreach ( $tabs as $id => $tab ) : ?>
				<a href="<?php echo esc_url( farol_settings_url( $id ) ); ?>" class="nav-tab<?php echo $id === $current ? ' nav-tab-active' : ''; ?>"<?php echo $id === $current ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( $current ) {
			call_user_func( $tabs[ $current ]['render'] );
		}
		?>
	</div>
	<?php
}
