/**
 * Webba Deploy admin scripts.
 */
( function () {
	'use strict';

	document.querySelectorAll( '.webba-deploy-copy' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var targetId = button.getAttribute( 'data-copy-target' );
			var input = targetId ? document.getElementById( targetId ) : null;

			if ( ! input ) {
				return;
			}

			input.select();
			input.setSelectionRange( 0, input.value.length );

			var copied = false;

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( function () {
					copied = true;
					showCopied( button );
				} );
			}

			if ( ! copied && document.execCommand ) {
				document.execCommand( 'copy' );
				showCopied( button );
			}
		} );
	} );

	function showCopied( button ) {
		var label = button.textContent;
		button.textContent = button.getAttribute( 'data-copied-label' ) || 'Copied!';
		window.setTimeout( function () {
			button.textContent = label;
		}, 2000 );
	}

	var repoInput = document.getElementById( 'repo_url' );
	var slugInput = document.getElementById( 'slug' );
	var branchInput = document.getElementById( 'branch' );
	var repoSelect = document.getElementById( 'repo_full' );
	var form = document.getElementById( 'webba-deploy-add-form' );

	if ( repoInput && slugInput ) {
		repoInput.addEventListener( 'blur', function () {
			if ( slugInput.value.trim() !== '' ) {
				return;
			}

			var match = repoInput.value.match( /github\.com\/[^/]+\/([^/?#]+)/i );

			if ( match && match[ 1 ] ) {
				slugInput.value = match[ 1 ].replace( /\.git$/, '' ).toLowerCase();
			}
		} );
	}

	if ( repoSelect ) {
		repoSelect.addEventListener( 'change', function () {
			var option = repoSelect.options[ repoSelect.selectedIndex ];

			if ( ! option || ! option.value ) {
				return;
			}

			var defaultBranch = option.getAttribute( 'data-default-branch' );

			if ( defaultBranch && branchInput ) {
				branchInput.value = defaultBranch;
			}

			if ( slugInput && slugInput.value.trim() === '' ) {
				var slug = option.getAttribute( 'data-slug' );

				if ( slug ) {
					slugInput.value = slug;
				}
			}
		} );
	}

	var visibilityRadios = document.querySelectorAll( '.webba-deploy-visibility' );
	var publicRow = document.querySelector( '.webba-deploy-field-public' );
	var privateRow = document.querySelector( '.webba-deploy-field-private' );

	function syncVisibilityFields() {
		var isPrivate = false;

		visibilityRadios.forEach( function ( radio ) {
			if ( radio.checked && radio.value === 'private' ) {
				isPrivate = true;
			}
		} );

		if ( publicRow ) {
			publicRow.hidden = isPrivate;
		}

		if ( privateRow ) {
			privateRow.hidden = ! isPrivate;
		}

		if ( repoInput ) {
			repoInput.required = ! isPrivate;
		}

		if ( repoSelect ) {
			repoSelect.required = isPrivate;
		}
	}

	visibilityRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', syncVisibilityFields );
	} );

	if ( visibilityRadios.length ) {
		syncVisibilityFields();
	}

	if ( form ) {
		form.addEventListener( 'submit', function () {
			syncVisibilityFields();
		} );
	}
}() );
