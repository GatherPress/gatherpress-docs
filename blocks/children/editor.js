/**
 * Editor script for the Doc Child Pages block.
 *
 * Plain JS on purpose — the plugin ships without a build step. The block is
 * server-rendered, so the editor only needs a recognizable placeholder.
 */
( function ( blocks, element, blockEditor, i18n ) {
	'use strict';

	blocks.registerBlockType( 'gatherpress-docs/children', {
		edit: function () {
			var blockProps = blockEditor.useBlockProps( {
				className: 'gatherpress-docs-children',
			} );

			return element.createElement(
				'ul',
				blockProps,
				element.createElement( 'li', null, i18n.__( 'Doc child pages (listed automatically)', 'gatherpress-docs' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n );
