/**
 * Editor script for the Doc Breadcrumbs block.
 *
 * Plain JS on purpose — the plugin ships without a build step. The block is
 * server-rendered, so the editor only needs a recognizable placeholder.
 */
( function ( blocks, element, blockEditor, i18n ) {
	'use strict';

	blocks.registerBlockType( 'gatherpress-docs/breadcrumbs', {
		edit: function () {
			var blockProps = blockEditor.useBlockProps( {
				className: 'gatherpress-docs-breadcrumbs',
			} );

			return element.createElement(
				'nav',
				blockProps,
				i18n.__( 'Doc breadcrumbs (docs root / … / current document)', 'gatherpress-docs' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
