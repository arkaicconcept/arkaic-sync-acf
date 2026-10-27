<?php
/**
 * Plugin Name: Arkaic Sync ACF
 * Description: Pousse les articles cochés (champs ACF, images, catégories) vers un autre site WordPress.
 * Version: 0.6.0
 *
 * À installer sur la source ET sur la cible, puis configurer dans Réglages > Arkaic Sync.
 * L'authentification utilise un jeton partagé (en-tête X-Arkaic-Token), pas l'en-tête Authorization
 * que certains hébergeurs suppriment.
 *
 * Alternative aux réglages : définir ARKAIC_SYNC_TARGET, ARKAIC_SYNC_SEND_TOKEN, ARKAIC_SYNC_RECEIVE_TOKEN
 * et ARKAIC_SYNC_RECEIVE_USER dans wp-config.php (prioritaire).
 */

defined( 'ABSPATH' ) || exit;

const ARKAIC_SYNC_VERSION     = '0.6.0';
const ARKAIC_SYNC_GITHUB_REPO = 'arkaicconcept/arkaic-sync-acf';
const ARKAIC_SYNC_META        = '_arkaic_sync_enabled';
const ARKAIC_SYNC_EXCLUDED    = array( '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme' );

/* ---------------------------------------------------------------------------
 * Réglages
 * ------------------------------------------------------------------------ */

// Une constante de wp-config.php l'emporte sur la valeur saisie dans les réglages.
function arkaic_sync_setting( $name ) {
	$constant = 'ARKAIC_SYNC_' . strtoupper( $name );
	if ( defined( $constant ) ) {
		return constant( $constant );
	}
	$options = get_option( 'arkaic_sync_settings', array() );
	return isset( $options[ $name ] ) ? $options[ $name ] : '';
}

function arkaic_sync_configured() {
	return arkaic_sync_setting( 'target' ) && arkaic_sync_setting( 'send_token' );
}

add_action( 'admin_menu', function () {
	add_options_page( 'Arkaic Sync', 'Arkaic Sync', 'manage_options', 'arkaic-sync', 'arkaic_sync_settings_page' );
} );

function arkaic_sync_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options = get_option( 'arkaic_sync_settings', array() );

	$submitted = isset( $_POST['arkaic_sync_save'] ) || isset( $_POST['arkaic_sync_test'] ) || isset( $_POST['arkaic_sync_regen'] ) || isset( $_POST['arkaic_sync_check_updates'] );
	if ( $submitted && check_admin_referer( 'arkaic_sync_settings' ) ) {
		$options['target']       = esc_url_raw( untrailingslashit( trim( wp_unslash( $_POST['target'] ?? '' ) ) ) );
		$options['receive_user'] = absint( $_POST['receive_user'] ?? 0 );
		if ( ! empty( $_POST['send_token'] ) ) {
			$options['send_token'] = sanitize_text_field( wp_unslash( $_POST['send_token'] ) );
		}
		if ( isset( $_POST['github_token'] ) ) {
			$options['github_token'] = sanitize_text_field( wp_unslash( $_POST['github_token'] ) );
		}
		if ( isset( $_POST['arkaic_sync_regen'] ) ) {
			$options['receive_token'] = '';
		}
		if ( isset( $_POST['arkaic_sync_check_updates'] ) ) {
			delete_site_transient( 'arkaic_sync_gh_release' );
			delete_site_transient( 'update_plugins' );
		}
	}
	if ( empty( $options['receive_token'] ) ) {
		$options['receive_token'] = wp_generate_password( 40, false );
	}
	if ( empty( $options['receive_user'] ) ) {
		$options['receive_user'] = get_current_user_id();
	}
	update_option( 'arkaic_sync_settings', $options, false );

	if ( $submitted ) {
		if ( isset( $_POST['arkaic_sync_check_updates'] ) ) {
			echo '<div class="notice notice-info"><p>Recherche des mises à jour GitHub effectuée.</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
		}
	}
	if ( isset( $_POST['arkaic_sync_test'] ) ) {
		$ping = arkaic_sync_request( 'GET', '/ping' );
		if ( is_wp_error( $ping ) ) {
			printf( '<div class="notice notice-error"><p>Connexion impossible : %s<br>Vérifie que le plugin est actif sur la cible, que l\'URL est exacte et que le jeton est celui affiché sur la cible.</p></div>', esc_html( $ping->get_error_message() ) );
		} else {
			printf( '<div class="notice notice-success"><p>Connexion réussie. La cible importera les articles au nom de %s.</p></div>', esc_html( $ping['name'] ) );
		}
	}
	?>
	<div class="wrap">
		<h1>Arkaic Sync</h1>
		<form method="post">
			<?php wp_nonce_field( 'arkaic_sync_settings' ); ?>

			<h2>Recevoir (ce site est la cible)</h2>
			<table class="form-table">
				<tr>
					<th>Jeton de ce site</th>
					<td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( arkaic_sync_setting( 'receive_token' ) ); ?>">
					<p class="description">À copier dans le champ « Jeton de la cible » du site qui envoie.</p>
					<button type="submit" name="arkaic_sync_regen" class="button">Régénérer le jeton</button></td>
				</tr>
				<tr>
					<th><label for="receive_user">Importer au nom de</label></th>
					<td><?php wp_dropdown_users( array( 'name' => 'receive_user', 'id' => 'receive_user', 'selected' => arkaic_sync_setting( 'receive_user' ), 'role__in' => array( 'administrator', 'editor' ) ) ); ?></td>
				</tr>
			</table>

			<h2>Envoyer (ce site est la source)</h2>
			<table class="form-table">
				<tr>
					<th><label for="target">Site cible</label></th>
					<td><input type="url" id="target" name="target" class="regular-text" placeholder="https://exemple.com" value="<?php echo esc_attr( arkaic_sync_setting( 'target' ) ); ?>">
					<p class="description">URL racine du WordPress qui reçoit les articles (en https).</p></td>
				</tr>
				<tr>
					<th><label for="send_token">Jeton de la cible</label></th>
					<td><input type="password" id="send_token" name="send_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo arkaic_sync_setting( 'send_token' ) ? '(inchangé)' : ''; ?>">
					<p class="description">Le jeton affiché dans « Recevoir » sur le site cible.</p></td>
				</tr>
			</table>

			<h2>Mises à jour (GitHub)</h2>
			<table class="form-table">
				<tr>
					<th>Dépôt GitHub</th>
					<td>
						<code><?php echo esc_html( ARKAIC_SYNC_GITHUB_REPO ); ?></code>
						<a href="https://github.com/<?php echo esc_attr( ARKAIC_SYNC_GITHUB_REPO ); ?>" target="_blank" class="button button-small" style="margin-left:8px;">Voir sur GitHub</a>
					</td>
				</tr>
				<tr>
					<th>Version installée</th>
					<td><strong><?php echo esc_html( ARKAIC_SYNC_VERSION ); ?></strong></td>
				</tr>
				<tr>
					<th>État des mises à jour</th>
					<td>
						<?php
						$release = arkaic_sync_get_latest_github_release();
						if ( $release && ! empty( $release['tag_name'] ) ) {
							$remote = ltrim( $release['tag_name'], 'v' );
							if ( version_compare( $remote, ARKAIC_SYNC_VERSION, '>' ) ) {
								printf(
									'<p><span style="color:#d63638;font-weight:600;">Version %s disponible !</span> &mdash; <a href="%s" class="button button-primary button-small">Mettre à jour via WordPress</a></p>',
									esc_html( $remote ),
									esc_url( admin_url( 'update-core.php' ) )
								);
							} else {
								printf( '<p><span style="color:#1a7f37;font-weight:600;">À jour</span> (version %s)</p>', esc_html( $remote ) );
							}
						} else {
							echo '<p><em>Aucune version distante trouvée pour le moment.</em></p>';
						}
						?>
						<button type="submit" name="arkaic_sync_check_updates" class="button button-secondary">Vérifier les mises à jour maintenant</button>
					</td>
				</tr>
				<tr>
					<th><label for="github_token">Jeton GitHub (optionnel)</label></th>
					<td>
						<input type="password" id="github_token" name="github_token" class="regular-text" autocomplete="new-password" placeholder="<?php echo arkaic_sync_setting( 'github_token' ) ? '(inchangé)' : ''; ?>">
						<p class="description">Non requis si le dépôt est public. Utile uniquement si le dépôt est privé ou en cas de quota d'API dépassé.</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="arkaic_sync_save" class="button button-primary">Enregistrer</button>
				<button type="submit" name="arkaic_sync_test" class="button">Enregistrer et tester la connexion</button>
			</p>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Source : case à cocher + envoi à l'enregistrement
 * ------------------------------------------------------------------------ */

add_action( 'add_meta_boxes', function () {
	if ( arkaic_sync_configured() ) {
		add_meta_box( 'arkaic-sync', 'Synchronisation', 'arkaic_sync_render_box', 'post', 'side' );
	}
} );

function arkaic_sync_render_box( $post ) {
	wp_nonce_field( 'arkaic_sync', 'arkaic_sync_nonce' );
	printf(
		'<label><input type="checkbox" name="arkaic_sync_enabled" value="1" %s> Synchroniser vers %s</label>',
		checked( get_post_meta( $post->ID, ARKAIC_SYNC_META, true ), '1', false ),
		esc_html( arkaic_sync_setting( 'target' ) )
	);
	$last = get_post_meta( $post->ID, '_arkaic_sync_last', true );
	if ( $last ) {
		printf( '<p class="description">%s</p>', esc_html( $last ) );
	}
}

// Synchronise un article unique (utilisé à l'enregistrement, via le bouton de la liste ou en masse).
function arkaic_sync_do_sync( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return array(
			'status'  => 'error',
			'message' => 'Article invalide ou dans la corbeille.',
		);
	}
	update_post_meta( $post_id, ARKAIC_SYNC_META, '1' );

	$result = arkaic_sync_push( $post );
	if ( is_wp_error( $result ) ) {
		$message = 'Échec de la synchro : ' . $result->get_error_message();
		$type    = 'error';
	} else {
		$message = 'Synchronisé le ' . wp_date( 'd/m/Y H:i' ) . ' (article ' . $result['id'] . ', ' . $result['meta'] . ' champs envoyés)';
		$type    = 'success';
	}
	update_post_meta( $post_id, '_arkaic_sync_last', $message );
	update_post_meta( $post_id, '_arkaic_sync_status', $type );

	return array(
		'status'  => $type,
		'message' => $message,
		'result'  => $result,
	);
}

// Action générique, pas save_post_post : celle-ci passe avant l'enregistrement des champs ACF (save_post, priorité 10).
add_action( 'save_post', 'arkaic_sync_on_save', 99, 2 );

function arkaic_sync_on_save( $post_id, $post ) {
	if ( 'post' !== $post->post_type || ! arkaic_sync_configured() || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}
	// Le nonce n'existe que dans la soumission du formulaire (pas dans l'enregistrement REST de Gutenberg).
	if ( ! isset( $_POST['arkaic_sync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arkaic_sync_nonce'] ) ), 'arkaic_sync' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( empty( $_POST['arkaic_sync_enabled'] ) ) {
		delete_post_meta( $post_id, ARKAIC_SYNC_META );
		return;
	}
	if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return;
	}

	$res = arkaic_sync_do_sync( $post_id );
	set_transient( 'arkaic_sync_notice_' . get_current_user_id(), array( $res['status'], $res['message'] ), 60 );
}

/* ---------------------------------------------------------------------------
 * Source : colonne et actions sur la liste des articles (edit.php)
 * ------------------------------------------------------------------------ */

// Ajout de la colonne "Synchronisation" après le titre
add_filter( 'manage_post_posts_columns', function ( $columns ) {
	if ( ! arkaic_sync_configured() ) {
		return $columns;
	}
	$new = array();
	foreach ( $columns as $key => $title ) {
		$new[ $key ] = $title;
		if ( 'title' === $key ) {
			$new['arkaic_sync'] = 'Synchronisation';
		}
	}
	if ( ! isset( $new['arkaic_sync'] ) ) {
		$new['arkaic_sync'] = 'Synchronisation';
	}
	return $new;
} );

// Contenu HTML d'une cellule de synchronisation
function arkaic_sync_render_cell_content( $post_id ) {
	$last   = get_post_meta( $post_id, '_arkaic_sync_last', true );
	$status = get_post_meta( $post_id, '_arkaic_sync_status', true );
	if ( ! $status && $last ) {
		$status = ( 0 === strpos( $last, 'Synchronisé' ) ) ? 'success' : 'error';
	}

	$nonce    = wp_create_nonce( 'arkaic_sync_action_' . $post_id );
	$sync_url = wp_nonce_url( admin_url( 'admin-post.php?action=arkaic_sync_post&post_id=' . $post_id ), 'arkaic_sync_action_' . $post_id );

	ob_start();
	?>
	<div class="arkaic-sync-cell" id="arkaic-sync-cell-<?php echo esc_attr( $post_id ); ?>">
		<?php if ( 'success' === $status ) :
			$date_str = '';
			if ( preg_match( '/Synchronisé le ([^(\n]+)/', $last, $m ) ) {
				$date_str = trim( $m[1] );
			}
		?>
			<div style="margin-bottom:4px;">
				<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600;background:#edfaef;color:#1a7f37;border:1px solid #c3e6cb;" title="<?php echo esc_attr( $last ); ?>">
					✔ Synchronisé
				</span>
			</div>
			<?php if ( $date_str ) : ?>
				<div style="font-size:11px;color:#646970;margin-bottom:4px;" title="<?php echo esc_attr( $last ); ?>">
					<?php echo esc_html( $date_str ); ?>
				</div>
			<?php endif; ?>
			<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small arkaic-sync-btn" data-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				Resynchroniser
			</a>
		<?php elseif ( 'error' === $status ) : ?>
			<div style="margin-bottom:4px;">
				<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:600;background:#fcf0f2;color:#d63638;border:1px solid #f5c2c7;" title="<?php echo esc_attr( $last ); ?>">
					⚠ Échec
				</span>
			</div>
			<?php if ( $last ) : ?>
				<div style="font-size:11px;color:#d63638;margin-bottom:4px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $last ); ?>">
					<?php echo esc_html( $last ); ?>
				</div>
			<?php endif; ?>
			<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small arkaic-sync-btn" data-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="color:#d63638;border-color:#d63638;">
				Réessayer
			</a>
		<?php else : ?>
			<div style="margin-bottom:4px;">
				<span style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:500;background:#f0f0f1;color:#646970;">
					Non synchronisé
				</span>
			</div>
			<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small button-primary arkaic-sync-btn" data-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
				Synchroniser
			</a>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

// Rendu de la colonne
add_action( 'manage_post_posts_custom_column', function ( $column, $post_id ) {
	if ( 'arkaic_sync' === $column ) {
		echo arkaic_sync_render_cell_content( $post_id );
	}
}, 10, 2 );

// Action rapide sous le titre de l'article (au survol)
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'post' !== $post->post_type || ! arkaic_sync_configured() ) {
		return $actions;
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return $actions;
	}
	$status   = get_post_meta( $post->ID, '_arkaic_sync_status', true );
	$nonce    = wp_create_nonce( 'arkaic_sync_action_' . $post->ID );
	$sync_url = wp_nonce_url( admin_url( 'admin-post.php?action=arkaic_sync_post&post_id=' . $post->ID ), 'arkaic_sync_action_' . $post->ID );
	$label    = ( 'success' === $status ) ? 'Resynchroniser' : 'Synchroniser';

	$actions['arkaic_sync'] = sprintf(
		'<a href="%s" class="arkaic-sync-btn" data-id="%d" data-nonce="%s">%s</a>',
		esc_url( $sync_url ),
		esc_attr( $post->ID ),
		esc_attr( $nonce ),
		esc_html( $label )
	);
	return $actions;
}, 10, 2 );

// Déclencheur standard (GET/POST avec redirection)
add_action( 'admin_post_arkaic_sync_post', function () {
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	check_admin_referer( 'arkaic_sync_action_' . $post_id );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( 'Non autorisé.' );
	}

	$res = arkaic_sync_do_sync( $post_id );
	set_transient( 'arkaic_sync_notice_' . get_current_user_id(), array( $res['status'], $res['message'] ), 60 );

	$referer = wp_get_referer();
	wp_safe_redirect( $referer ? $referer : admin_url( 'edit.php' ) );
	exit;
} );

// Déclencheur AJAX direct (sans rechargement de page)
add_action( 'wp_ajax_arkaic_sync_ajax', function () {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'arkaic_sync_action_' . $post_id ) ) {
		wp_send_json_error( array( 'message' => 'Lien de sécurité expiré. Veuillez rafraîchir la page.' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => 'Vous n\'avez pas les permissions pour synchroniser cet article.' ) );
	}

	$res = arkaic_sync_do_sync( $post_id );

	wp_send_json_success( array(
		'status'  => $res['status'],
		'message' => $res['message'],
		'html'    => arkaic_sync_render_cell_content( $post_id ),
	) );
} );

// Action groupée (Bulk action) dans la liste des articles
add_filter( 'bulk_actions-edit-post', function ( $bulk_actions ) {
	if ( arkaic_sync_configured() ) {
		$bulk_actions['arkaic_sync_bulk'] = 'Synchroniser vers la cible';
	}
	return $bulk_actions;
} );

add_filter( 'handle_bulk_actions-edit-post', function ( $redirect_to, $doaction, $post_ids ) {
	if ( 'arkaic_sync_bulk' !== $doaction ) {
		return $redirect_to;
	}
	$synced = 0;
	$errors = 0;
	foreach ( $post_ids as $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		$res = arkaic_sync_do_sync( $post_id );
		if ( 'success' === $res['status'] ) {
			$synced++;
		} else {
			$errors++;
		}
	}
	return add_query_arg( array(
		'arkaic_bulk_synced' => $synced,
		'arkaic_bulk_errors' => $errors,
	), $redirect_to );
}, 10, 3 );

// Style de la colonne sur edit.php
add_action( 'admin_head-edit.php', function () {
	global $post_type;
	if ( 'post' !== $post_type || ! arkaic_sync_configured() ) {
		return;
	}
	echo '<style>.column-arkaic_sync { width: 170px; }</style>';
} );

// Script AJAX sur edit.php
add_action( 'admin_footer-edit.php', function () {
	global $post_type;
	if ( 'post' !== $post_type || ! arkaic_sync_configured() ) {
		return;
	}
	?>
	<script>
	jQuery(function($) {
		$(document).on('click', '.arkaic-sync-btn', function(e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.hasClass('updating-message') || $btn.prop('disabled')) {
				return;
			}
			var postId = $btn.data('id');
			var nonce  = $btn.data('nonce');
			var oldHtml = $btn.html();

			$btn.addClass('updating-message').prop('disabled', true).text('Synchro...');

			$.post(ajaxurl, {
				action: 'arkaic_sync_ajax',
				post_id: postId,
				nonce: nonce
			}).done(function(res) {
				if (res && res.data && res.data.html) {
					var $cell = $('#arkaic-sync-cell-' + postId);
					if ($cell.length) {
						$cell.replaceWith(res.data.html);
					} else {
						window.location.reload();
					}
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Erreur inconnue';
					alert(msg);
					$btn.removeClass('updating-message').prop('disabled', false).html(oldHtml);
				}
			}).fail(function(xhr) {
				alert('Erreur réseau (' + xhr.status + ')');
				$btn.removeClass('updating-message').prop('disabled', false).html(oldHtml);
			});
		});
	});
	</script>
	<?php
} );

add_action( 'admin_notices', function () {
	$key    = 'arkaic_sync_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( $notice ) {
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}
	if ( isset( $_GET['arkaic_bulk_synced'] ) ) {
		$synced = (int) $_GET['arkaic_bulk_synced'];
		$errors = (int) ( $_GET['arkaic_bulk_errors'] ?? 0 );
		$msg    = sprintf( '%d article(s) synchronisé(s) vers la cible.', $synced );
		if ( $errors > 0 ) {
			$msg .= sprintf( ' (%d échec(s))', $errors );
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $errors > 0 ? 'warning' : 'success', esc_html( $msg ) );
	}
} );

/* ---------------------------------------------------------------------------
 * Source : appels vers la cible
 * ------------------------------------------------------------------------ */

function arkaic_sync_request( $method, $path, $args = array() ) {
	$args['method']                      = $method;
	$args['timeout']                     = 60;
	$args['headers']['X-Arkaic-Token']   = arkaic_sync_setting( 'send_token' );

	$response = wp_remote_request( untrailingslashit( arkaic_sync_setting( 'target' ) ) . '/wp-json/arkaic-sync/v1' . $path, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code     = wp_remote_retrieve_response_code( $response );
	$raw_body = wp_remote_retrieve_body( $response );
	$body     = json_decode( $raw_body, true );
	if ( $code < 200 || $code >= 300 ) {
		$message = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
		if ( 403 === $code && false !== stripos( $raw_body, 'wordfence' ) ) {
			$message = 'HTTP 403 (bloqué par le pare-feu Wordfence sur la cible)';
		}
		return new WP_Error( 'arkaic_sync_http', $message . ' (' . $path . ')' );
	}
	return $body;
}

function arkaic_sync_json( $path, $data ) {
	return arkaic_sync_request( 'POST', $path, array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $data ),
	) );
}

// Envoie un média sur la cible et renvoie son nouvel ID. La correspondance est mémorisée pour ne pas renvoyer le même fichier.
function arkaic_sync_media( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'arkaic_sync_file', 'Fichier introuvable pour le média ' . $attachment_id );
	}

	$map_key = 'arkaic_sync_media_map_' . md5( arkaic_sync_setting( 'target' ) );
	$map     = get_option( $map_key, array() );
	$hash    = md5_file( $file );
	if ( isset( $map[ $attachment_id ]['url'] ) && $map[ $attachment_id ]['hash'] === $hash ) {
		return $map[ $attachment_id ]['id'];
	}

	$url      = wp_get_attachment_url( $attachment_id );
	$filename = basename( $file );
	$title    = get_the_title( $attachment_id );
	$alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

	// On tente d'abord par URL (évite le transfert de flux binaire qui déclenche les WAF/Wordfence en HTTP 403).
	$media = null;
	if ( $url ) {
		$media = arkaic_sync_json( '/media', array(
			'url'      => $url,
			'filename' => $filename,
			'title'    => $title,
			'alt'      => $alt,
		) );
	}

	// Repli sur le flux binaire si pas d'URL ou si la cible utilise une ancienne version n'acceptant pas le JSON
	if ( ! $url || ( is_wp_error( $media ) && ( false !== strpos( $media->get_error_message(), '400' ) || false !== strpos( $media->get_error_message(), 'Nom de fichier manquant' ) ) ) ) {
		$query = http_build_query( array(
			'filename' => $filename,
			'title'    => $title,
			'alt'      => $alt,
		) );
		$media = arkaic_sync_request( 'POST', '/media?' . $query, array(
			'headers' => array( 'Content-Type' => 'application/octet-stream' ),
			'body'    => file_get_contents( $file ),
		) );
	}

	if ( is_wp_error( $media ) ) {
		return $media;
	}

	$map[ $attachment_id ] = array( 'id' => $media['id'], 'url' => $media['url'], 'hash' => $hash );
	update_option( $map_key, $map, false );
	return $media['id'];
}

// Retrouve (ou crée) le terme équivalent sur la cible, par slug.
function arkaic_sync_term( $term_id, $taxonomy ) {
	$term = get_term( (int) $term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	$result = arkaic_sync_json( '/term', array( 'taxonomy' => $taxonomy, 'slug' => $term->slug, 'name' => $term->name ) );
	return is_wp_error( $result ) ? $result : $result['id'];
}

// Remplace les IDs propres à la source (médias, termes) par ceux de la cible, selon le type du champ ACF.
function arkaic_sync_convert( $field, $value ) {
	if ( empty( $value ) ) {
		return $value;
	}
	switch ( $field['type'] ) {
		case 'image':
		case 'file':
			return arkaic_sync_media( (int) $value );

		case 'gallery':
			$ids = array();
			foreach ( (array) $value as $id ) {
				$new = arkaic_sync_media( (int) $id );
				if ( is_wp_error( $new ) ) {
					return $new;
				}
				$ids[] = $new;
			}
			return $ids;

		case 'taxonomy':
			$ids = array();
			foreach ( (array) $value as $id ) {
				$new = arkaic_sync_term( (int) $id, $field['taxonomy'] );
				if ( is_wp_error( $new ) ) {
					return $new;
				}
				if ( $new ) {
					$ids[] = $new;
				}
			}
			return is_array( $value ) ? $ids : ( $ids[0] ?? '' );
	}
	return $value;
}

// Copie toutes les meta de l'article. Chaque meta ACF est repérée par sa jumelle "_nom" qui contient la clé du champ.
function arkaic_sync_collect_meta( $post_id ) {
	$out = array();
	$raw = get_post_meta( $post_id );

	foreach ( $raw as $key => $values ) {
		// sps_* : marqueur de l'ancien plugin Sync Post With Other Site.
		if ( in_array( $key, ARKAIC_SYNC_EXCLUDED, true ) || 0 === strpos( $key, '_arkaic_sync' ) || 0 === strpos( $key, 'sps_' ) ) {
			continue;
		}
		$value = maybe_unserialize( $values[0] );

		if ( '_' !== $key[0] && isset( $raw[ '_' . $key ] ) && 0 === strpos( $raw[ '_' . $key ][0], 'field_' ) ) {
			$field = acf_get_field( $raw[ '_' . $key ][0] );
			if ( $field ) {
				$value = arkaic_sync_convert( $field, $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
		}
		$out[ $key ] = $value;
	}
	return $out;
}

// URL du média sur la cible, pour le HTML généré.
function arkaic_sync_media_url( $attachment_id ) {
	$id = arkaic_sync_media( (int) $attachment_id );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$map = get_option( 'arkaic_sync_media_map_' . md5( arkaic_sync_setting( 'target' ) ), array() );
	return $map[ $attachment_id ]['url'];
}

function arkaic_sync_img( $attachment_id ) {
	if ( ! $attachment_id ) {
		return '';
	}
	$url = arkaic_sync_media_url( $attachment_id );
	if ( is_wp_error( $url ) ) {
		return $url;
	}
	return sprintf(
		'<img src="%s" alt="%s" style="max-width:100%%;height:auto;">',
		esc_url( $url ),
		esc_attr( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) )
	);
}

// Le thème Divi de la cible n'affiche que le contenu natif : on transforme les blocs ACF en HTML.
// Note : l'image de tête (header_image) n'est pas injectée dans le HTML car elle est transmise en Image mise en avant (featured_media).
function arkaic_sync_render_html( $post ) {
	$get  = function ( $key ) use ( $post ) {
		return get_post_meta( $post->ID, $key, true );
	};
	$html = '';

	$html .= $get( 'header_title' );

	foreach ( (array) $get( 'blocks' ) as $i => $layout ) {
		$prefix = 'blocks_' . $i . '_';

		switch ( $layout ) {
			case 'texte':
				$html .= $get( $prefix . 'text' );
				break;

			case 'image':
				$img = arkaic_sync_img( $get( $prefix . 'image' ) );
				if ( is_wp_error( $img ) ) {
					return $img;
				}
				$html .= '<p>' . $img . '</p>';
				break;

			case 'image_et_texte':
				$img = arkaic_sync_img( $get( $prefix . 'image' ) );
				if ( is_wp_error( $img ) ) {
					return $img;
				}
				$html .= sprintf(
					'<div style="display:flex;flex-direction:%s;gap:2rem;align-items:center;flex-wrap:wrap;margin:2rem 0;"><div style="flex:1 1 300px;">%s</div><div style="flex:1 1 300px;">%s</div></div>',
					$get( $prefix . 'side' ) ? 'row-reverse' : 'row',
					$img,
					$get( $prefix . 'text' )
				);
				break;

			case 'galerie':
			case 'slider':
				$html .= '<div style="display:flex;flex-wrap:wrap;gap:1rem;margin:2rem 0;">';
				foreach ( (array) $get( $prefix . $layout ) as $attachment_id ) {
					$img = arkaic_sync_img( $attachment_id );
					if ( is_wp_error( $img ) ) {
						return $img;
					}
					$html .= '<div style="flex:1 1 200px;">' . $img . '</div>';
				}
				$html .= '</div>';
				break;

			case 'video':
				// Une URL seule sur sa ligne est transformée en lecteur par WordPress.
				if ( $get( $prefix . 'video' ) ) {
					$html .= "\n\n" . esc_url( $get( $prefix . 'video' ) ) . "\n\n";
				}
				break;

			case 'button':
				if ( $get( $prefix . 'button_url' ) ) {
					$html .= sprintf(
						'<p><a class="et_pb_button" href="%s">%s</a></p>',
						esc_url( $get( $prefix . 'button_url' ) ),
						esc_html( $get( $prefix . 'button_text' ) )
					);
				}
				break;
		}
	}
	return $html;
}

function arkaic_sync_push( $post ) {
	if ( ! function_exists( 'acf_get_field' ) ) {
		return new WP_Error( 'arkaic_sync_acf', 'ACF doit être actif sur la source.' );
	}

	$meta = arkaic_sync_collect_meta( $post->ID );
	if ( is_wp_error( $meta ) ) {
		return $meta;
	}

	$featured     = 0;
	$header_image = get_post_meta( $post->ID, 'header_image', true );
	$header_media = get_post_meta( $post->ID, 'header_media', true );
	if ( '' !== $header_media && empty( $header_media ) ) {
		$header_image = 0;
	}
	$thumb = $header_image;
	if ( is_array( $thumb ) ) {
		$thumb = ! empty( $thumb['id'] ) ? $thumb['id'] : 0;
	}
	if ( ! $thumb ) {
		$thumb = get_post_thumbnail_id( $post );
	}
	if ( $thumb ) {
		$featured = arkaic_sync_media( (int) $thumb );
		if ( is_wp_error( $featured ) ) {
			return $featured;
		}
	}

	$terms = array();
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		foreach ( wp_get_object_terms( $post->ID, $taxonomy ) as $term ) {
			$id = arkaic_sync_term( $term->term_id, $taxonomy );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( $id ) {
				$terms[ $taxonomy ][] = $id;
			}
		}
	}

	$content = $post->post_content;
	if ( '' === trim( $content ) ) {
		$content = arkaic_sync_render_html( $post );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
	}
	$excerpt = $post->post_excerpt ? $post->post_excerpt : get_post_meta( $post->ID, 'summary', true );

	return arkaic_sync_json( '/post', array(
		'title'          => $post->post_title,
		'slug'           => $post->post_name,
		'status'         => $post->post_status,
		'date_gmt'       => in_array( $post->post_status, array( 'publish', 'future' ), true ) ? $post->post_date_gmt : '',
		'content'        => $content,
		'excerpt'        => $excerpt,
		'featured_media' => $featured,
		'terms'          => $terms,
		'meta'           => $meta,
	) );
}

/* ---------------------------------------------------------------------------
 * Cible : réception
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	$permission = 'arkaic_sync_permission';
	register_rest_route( 'arkaic-sync/v1', '/ping', array( 'methods' => 'GET', 'callback' => 'arkaic_sync_receive_ping', 'permission_callback' => $permission ) );
	register_rest_route( 'arkaic-sync/v1', '/media', array( 'methods' => 'POST', 'callback' => 'arkaic_sync_receive_media', 'permission_callback' => $permission ) );
	register_rest_route( 'arkaic-sync/v1', '/term', array( 'methods' => 'POST', 'callback' => 'arkaic_sync_receive_term', 'permission_callback' => $permission ) );
	register_rest_route( 'arkaic-sync/v1', '/post', array( 'methods' => 'POST', 'callback' => 'arkaic_sync_receive', 'permission_callback' => $permission ) );
} );

// Vérifie le jeton, puis exécute la requête au nom de l'utilisateur choisi dans les réglages.
function arkaic_sync_permission( WP_REST_Request $request ) {
	$expected = arkaic_sync_setting( 'receive_token' );
	$given    = (string) $request->get_header( 'x-arkaic-token' );
	if ( ! $expected || ! hash_equals( $expected, $given ) ) {
		return false;
	}
	$user_id = (int) arkaic_sync_setting( 'receive_user' );
	if ( ! $user_id || ! user_can( $user_id, 'edit_others_posts' ) ) {
		return new WP_Error( 'arkaic_sync_user', 'Aucun utilisateur valide n\'est choisi dans Réglages > Arkaic Sync de la cible.', array( 'status' => 500 ) );
	}
	wp_set_current_user( $user_id );
	return true;
}

// Encadré réservé aux administrateurs, visible seulement sur les articles reçus : liste les meta et vérifie leurs clés ACF.
add_action( 'add_meta_boxes_post', function ( $post ) {
	if ( ( get_post_meta( $post->ID, '_arkaic_sync_received', true ) || arkaic_sync_configured() ) && current_user_can( 'manage_options' ) ) {
		add_meta_box( 'arkaic-sync-diag', 'Arkaic Sync : diagnostic', 'arkaic_sync_render_diag', 'post', 'normal' );
	}
} );

function arkaic_sync_render_diag( $post ) {
	$received = get_post_meta( $post->ID, '_arkaic_sync_received', true );
	printf( '<p>%s</p>', esc_html( $received ? 'Reçu de la source le ' . $received . '.' : 'Meta de cet article, telles qu\'elles sont en base.' ) );

	$meta = get_post_meta( $post->ID );
	ksort( $meta );
	echo '<table class="widefat striped"><thead><tr><th>Clé</th><th>Valeur</th><th>Champ ACF</th><th>Lignes</th></tr></thead><tbody>';
	foreach ( array_slice( $meta, 0, 200, true ) as $key => $values ) {
		$ref = '_' !== $key[0] && isset( $meta[ '_' . $key ] ) ? $meta[ '_' . $key ][0] : '';
		$acf = '—';
		if ( 0 === strpos( $ref, 'field_' ) ) {
			if ( ! function_exists( 'acf_get_field' ) ) {
				$acf = 'ACF inactif';
			} else {
				$acf = acf_get_field( $ref ) ? 'ok' : 'introuvable sur cette cible';
			}
		}
		// Plusieurs lignes pour une même clé : on les montre toutes, seule la première est lue par get_post_meta( …, true ).
		$shown = implode( ' | ', array_map( function ( $v ) {
			return mb_strimwidth( (string) $v, 0, 80, '…' );
		}, $values ) );
		printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>', esc_html( $key ), esc_html( $shown ), esc_html( $acf ), count( $values ) );
	}
	echo '</tbody></table>';
}

function arkaic_sync_receive_ping() {
	return array( 'name' => wp_get_current_user()->display_name );
}

function arkaic_sync_receive_media( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$url      = esc_url_raw( (string) $request->get_param( 'url' ) );
	$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
	$title    = sanitize_text_field( (string) $request->get_param( 'title' ) );
	$alt      = sanitize_text_field( (string) $request->get_param( 'alt' ) );

	if ( ! $filename && ! $url ) {
		return new WP_Error( 'arkaic_sync_payload', 'URL ou nom de fichier manquant.', array( 'status' => 400 ) );
	}
	if ( ! $filename ) {
		$filename = sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) );
	}

	if ( $url ) {
		// Téléchargement propre depuis l'URL source (évite les blocages WAF / Wordfence sur les flux binaires).
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'arkaic_sync_download', 'Échec du téléchargement du média : ' . $tmp->get_error_message(), array( 'status' => 500 ) );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, 0, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return $id;
		}
	} else {
		$upload = wp_upload_bits( $filename, null, $request->get_body() );
		if ( $upload['error'] ) {
			return new WP_Error( 'arkaic_sync_upload', $upload['error'], array( 'status' => 500 ) );
		}

		$type = wp_check_filetype( $upload['file'] );
		$id   = wp_insert_attachment( array(
			'post_mime_type' => $type['type'],
			'post_title'     => $title,
			'post_status'    => 'inherit',
		), $upload['file'], 0, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	}

	if ( $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
}

function arkaic_sync_receive_term( WP_REST_Request $request ) {
	$data     = $request->get_json_params();
	$taxonomy = isset( $data['taxonomy'] ) ? $data['taxonomy'] : '';
	if ( ! taxonomy_exists( $taxonomy ) || empty( $data['slug'] ) ) {
		return new WP_Error( 'arkaic_sync_payload', 'Taxonomie ou slug invalide.', array( 'status' => 400 ) );
	}

	$term = get_term_by( 'slug', $data['slug'], $taxonomy );
	if ( $term ) {
		return array( 'id' => $term->term_id );
	}
	$created = wp_insert_term( $data['name'], $taxonomy, array( 'slug' => $data['slug'] ) );
	return is_wp_error( $created ) ? $created : array( 'id' => $created['term_id'] );
}

function arkaic_sync_receive( WP_REST_Request $request ) {
	$data = $request->get_json_params();
	if ( empty( $data['slug'] ) || empty( $data['title'] ) ) {
		return new WP_Error( 'arkaic_sync_payload', 'Titre ou slug manquant.', array( 'status' => 400 ) );
	}

	$args = array(
		'post_type'    => 'post',
		'post_title'   => $data['title'],
		'post_name'    => $data['slug'],
		'post_status'  => $data['status'],
		'post_content' => $data['content'],
		'post_excerpt' => $data['excerpt'],
	);
	if ( ! empty( $data['date_gmt'] ) ) {
		$args['post_date_gmt'] = $data['date_gmt'];
		$args['post_date']     = get_date_from_gmt( $data['date_gmt'] );
	}

	$existing = get_posts( array(
		'name'        => $data['slug'],
		'post_type'   => 'post',
		'post_status' => 'any',
		'numberposts' => 1,
	) );
	if ( $existing ) {
		$args['ID'] = $existing[0]->ID;
	}

	// Les filtres de sécurité des contenus sont posés au chargement, avant que le jeton identifie l'utilisateur : on les retire pour un utilisateur qui en a le droit.
	if ( current_user_can( 'unfiltered_html' ) ) {
		kses_remove_filters();
	}

	$post_id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	foreach ( (array) $data['terms'] as $taxonomy => $ids ) {
		wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $taxonomy );
	}

	if ( ! empty( $data['featured_media'] ) ) {
		set_post_thumbnail( $post_id, (int) $data['featured_media'] );
		update_post_meta( $post_id, '_thumbnail_id', (int) $data['featured_media'] );
	} else {
		delete_post_thumbnail( $post_id );
	}

	$meta = (array) $data['meta'];

	// Supprime les champs ACF qui n'existent plus sur la source (lignes de blocs retirées, par exemple).
	foreach ( get_post_meta( $post_id ) as $key => $values ) {
		if ( '_' !== $key[0] && ! array_key_exists( $key, $meta ) ) {
			$field_key = get_post_meta( $post_id, '_' . $key, true );
			if ( is_string( $field_key ) && 0 === strpos( $field_key, 'field_' ) ) {
				delete_post_meta( $post_id, $key );
				delete_post_meta( $post_id, '_' . $key );
			}
		}
	}

	foreach ( $meta as $key => $value ) {
		if ( ! in_array( $key, ARKAIC_SYNC_EXCLUDED, true ) ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	update_post_meta( $post_id, '_arkaic_sync_received', current_time( 'mysql' ) );

	return array( 'id' => $post_id, 'link' => get_permalink( $post_id ), 'meta' => count( $meta ) );
}

/* ---------------------------------------------------------------------------
 * Mises à jour automatiques depuis GitHub
 * ------------------------------------------------------------------------ */

// Récupère la dernière release publiée sur le dépôt GitHub
function arkaic_sync_get_latest_github_release( $force = false ) {
	$transient_key = 'arkaic_sync_gh_release';
	if ( ! $force ) {
		$cached = get_site_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$url  = 'https://api.github.com/repos/' . ARKAIC_SYNC_GITHUB_REPO . '/releases/latest';
	$args = array(
		'timeout' => 10,
		'headers' => array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/ArkaicSync-' . ARKAIC_SYNC_VERSION,
		),
	);

	$token = arkaic_sync_setting( 'github_token' );
	if ( $token ) {
		$args['headers']['Authorization'] = 'Bearer ' . $token;
	}

	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
		set_site_transient( $transient_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	return false;
}

// Filtre WordPress : injecte la mise à jour directement dans l'onglet Extensions (plugins.php)
function arkaic_sync_inject_update( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	$release = arkaic_sync_get_latest_github_release();
	if ( ! $release || empty( $release['tag_name'] ) ) {
		return $transient;
	}

	$remote_version  = ltrim( $release['tag_name'], 'v' );
	$current_version = ARKAIC_SYNC_VERSION;
	$plugin_file     = plugin_basename( __FILE__ );

	// Cherche l'archive release asset 'arkaic-sync-acf.zip' en priorité, repli sur zipball
	$package = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( 'arkaic-sync-acf.zip' === $asset['name'] && ! empty( $asset['browser_download_url'] ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
	}
	if ( ! $package && ! empty( $release['zipball_url'] ) ) {
		$package = $release['zipball_url'];
	}

	$item = (object) array(
		'id'           => 'arkaic-sync-acf',
		'slug'         => 'arkaic-sync-acf',
		'plugin'       => $plugin_file,
		'new_version'  => $remote_version,
		'url'          => $release['html_url'] ?? ( 'https://github.com/' . ARKAIC_SYNC_GITHUB_REPO ),
		'package'      => $package,
		'requires'     => '5.8',
		'requires_php' => '7.4',
		'tested'       => '6.7',
	);

	if ( version_compare( $remote_version, $current_version, '>' ) ) {
		$transient->response[ $plugin_file ] = $item;
		if ( isset( $transient->no_update[ $plugin_file ] ) ) {
			unset( $transient->no_update[ $plugin_file ] );
		}
	} else {
		$transient->no_update[ $plugin_file ] = $item;
		if ( isset( $transient->response[ $plugin_file ] ) ) {
			unset( $transient->response[ $plugin_file ] );
		}
	}

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'arkaic_sync_inject_update' );
add_filter( 'site_transient_update_plugins', 'arkaic_sync_inject_update' );

// Liens d'action directs sur la ligne du plugin dans Extensions (plugins.php)
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$check_url = wp_nonce_url( admin_url( 'plugins.php?arkaic_sync_check_now=1' ), 'arkaic_sync_check_nonce' );
	$custom    = array(
		'settings' => '<a href="' . esc_url( admin_url( 'options-general.php?page=arkaic-sync' ) ) . '">Réglages</a>',
		'check'    => '<a href="' . esc_url( $check_url ) . '" title="Vérifier immédiatement les mises à jour sur GitHub">Vérifier les MAJ</a>',
	);
	return array_merge( $custom, $links );
} );

// Déclencheur du lien "Vérifier les MAJ" depuis l'onglet Extensions
add_action( 'admin_init', function () {
	if ( isset( $_GET['arkaic_sync_check_now'] ) && check_admin_referer( 'arkaic_sync_check_nonce' ) ) {
		delete_site_transient( 'arkaic_sync_gh_release' );
		delete_site_transient( 'update_plugins' );
		set_transient( 'arkaic_sync_notice_' . get_current_user_id(), array( 'info', 'Recherche des mises à jour Arkaic Sync effectuée.' ), 30 );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
} );

// Filtre WordPress : informations détaillées de la mise à jour (modal "Afficher les détails")
add_filter( 'plugins_api', function ( $res, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'arkaic-sync-acf' !== $args->slug ) {
		return $res;
	}

	$release = arkaic_sync_get_latest_github_release();
	if ( ! $release ) {
		return $res;
	}

	$remote_version = ltrim( $release['tag_name'] ?? ARKAIC_SYNC_VERSION, 'v' );
	$package        = '';
	if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		foreach ( $release['assets'] as $asset ) {
			if ( 'arkaic-sync-acf.zip' === $asset['name'] && ! empty( $asset['browser_download_url'] ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
	}
	if ( ! $package && ! empty( $release['zipball_url'] ) ) {
		$package = $release['zipball_url'];
	}

	$changelog = ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : 'Consultez les releases sur GitHub pour le journal complet.';

	return (object) array(
		'name'           => 'Arkaic Sync ACF',
		'slug'           => 'arkaic-sync-acf',
		'version'        => $remote_version,
		'author'         => '<a href="https://arkaic-concept.fr">Arkaic Concept</a>',
		'author_profile' => 'https://github.com/arkaicconcept',
		'homepage'       => 'https://github.com/' . ARKAIC_SYNC_GITHUB_REPO,
		'requires'       => '5.8',
		'requires_php'   => '7.4',
		'tested'         => '6.7',
		'download_link'  => $package,
		'sections'       => array(
			'description' => 'Pousse les articles cochés (champs ACF, images, catégories) vers un autre site WordPress.',
			'changelog'   => $changelog,
		),
	);
}, 20, 3 );

// Filtre WordPress : normalisation du nom du dossier après extraction
add_filter( 'upgrader_post_install', function ( $true, $hook_extra, $result ) {
	global $wp_filesystem;
	if ( empty( $hook_extra['plugin'] ) || 'arkaic-sync-acf/arkaic-sync-acf.php' !== $hook_extra['plugin'] ) {
		return $true;
	}

	$proper_destination = WP_PLUGIN_DIR . '/arkaic-sync-acf';
	if ( isset( $result['destination'] ) && $result['destination'] !== $proper_destination ) {
		$wp_filesystem->move( $result['destination'], $proper_destination );
		$result['destination'] = $proper_destination;
	}

	if ( ! is_plugin_active( 'arkaic-sync-acf/arkaic-sync-acf.php' ) ) {
		activate_plugin( 'arkaic-sync-acf/arkaic-sync-acf.php' );
	}

	return $true;
}, 10, 3 );

// En-têtes HTTP pour les requêtes GitHub (jeton d'authentification et User-Agent)
add_filter( 'http_request_args', function ( $args, $url ) {
	if ( false !== strpos( $url, 'api.github.com/repos/' . ARKAIC_SYNC_GITHUB_REPO ) || false !== strpos( $url, 'github.com/' . ARKAIC_SYNC_GITHUB_REPO . '/releases' ) ) {
		$token = arkaic_sync_setting( 'github_token' );
		if ( $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		if ( ! isset( $args['headers']['User-Agent'] ) ) {
			$args['headers']['User-Agent'] = 'WordPress/ArkaicSync-' . ARKAIC_SYNC_VERSION;
		}
	}
	return $args;
}, 10, 2 );

// Effacement du cache lors du clic sur "Vérifier à nouveau" dans l'administration WordPress
add_action( 'load-update-core.php', function () {
	if ( isset( $_GET['force-check'] ) ) {
		delete_site_transient( 'arkaic_sync_gh_release' );
	}
} );
add_action( 'load-plugins.php', function () {
	if ( isset( $_GET['force-check'] ) ) {
		delete_site_transient( 'arkaic_sync_gh_release' );
	}
} );
