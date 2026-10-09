<?php
/** @var array $schema @var string $tab @var array $values */
defined( 'ABSPATH' ) || exit;
?>
<ul class="lnh-tabs">
	<?php foreach ( $schema as $slug => $def ) : ?>
		<li><a class="<?php echo $slug === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=lnh-settings&tab=' . $slug ) ); ?>"><?php echo esc_html( $def['label'] ); ?></a></li>
	<?php endforeach; ?>
</ul>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lnh-card-box lnh-settings">
	<input type="hidden" name="action" value="lnh_save_settings"><input type="hidden" name="lnh_tab" value="<?php echo esc_attr( $tab ); ?>">
	<?php wp_nonce_field( 'lnh_save_settings' ); ?>
	<table class="form-table" role="presentation"><tbody>
	<?php foreach ( $schema[ $tab ]['fields'] as $key => $f ) :
		$val = $values[ $key ] ?? '';
		$id  = 'lnh-f-' . $key;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f['label'] ); ?></label></th>
			<td>
				<?php
				switch ( $f['type'] ) {
					case 'checkbox':
						echo '<label class="lnh-switch"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="1"' . checked( (int) $val, 1, false ) . '><span class="lnh-switch__ui"></span></label>';
						break;
					case 'number':
						echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" min="' . (int) $f['min'] . '" max="' . (int) $f['max'] . '" value="' . (int) $val . '">';
						break;
					case 'select':
						echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
						foreach ( $f['options'] as $ov => $ol ) {
							echo '<option value="' . esc_attr( (string) $ov ) . '"' . selected( (string) $val, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
						}
						echo '</select>';
						break;
					case 'color':
						echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $val ) . '">';
						break;
					case 'textarea':
						echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( (string) $val ) . '</textarea>';
						break;
					case 'user':
						wp_dropdown_users( array( 'name' => $key, 'id' => $id, 'selected' => (int) $val, 'show_option_none' => __( '— keep as is —', 'lehigh-news-hub' ), 'option_none_value' => 0, 'capability' => 'edit_posts' ) );
						break;
					case 'category':
						wp_dropdown_categories( array( 'name' => $key, 'id' => $id, 'selected' => (int) $val, 'show_option_none' => __( '— none —', 'lehigh-news-hub' ), 'option_none_value' => 0, 'hide_empty' => false ) );
						break;
					case 'email':
						echo '<input type="email" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $val ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">';
						break;
					default:
						echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $val ) . '">';
				}
				if ( ! empty( $f['desc'] ) ) {
					echo '<p class="description">' . esc_html( $f['desc'] ) . '</p>';
				}
				?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody></table>
	<?php submit_button( __( 'Save changes', 'lehigh-news-hub' ) ); ?>
</form>
