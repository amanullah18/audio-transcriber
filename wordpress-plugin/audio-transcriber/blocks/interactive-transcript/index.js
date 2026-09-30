/**
 * Editor script for the Interactive Transcript block.
 *
 * Plain JavaScript on WordPress's globals (no build step). The front end is rendered in PHP, and the editor
 * shows the same markup through ServerSideRender.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { useBlockProps, MediaPlaceholder, BlockControls, MediaReplaceFlow } = wp.blockEditor;
	const ServerSideRender = wp.serverSideRender;
	const NAME = 'audio-transcriber/interactive-transcript';
	const TYPES = [ 'audio', 'video' ];

	wp.blocks.registerBlockType( NAME, {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const onSelect = ( media ) => setAttributes( { attachmentId: media && media.id ? media.id : undefined } );

			if ( ! attributes.attachmentId ) {
				return el(
					'div',
					blockProps,
					el( MediaPlaceholder, {
						icon: 'format-audio',
						labels: {
							title: __( 'Interactive Transcript', 'audio-transcriber' ),
							instructions: __( 'Choose an audio or video file. Transcribe it from the Media Library first.', 'audio-transcriber' ),
						},
						allowedTypes: TYPES,
						accept: 'audio/*,video/*',
						onSelect,
					} )
				);
			}

			return el(
				'div',
				blockProps,
				el(
					BlockControls,
					{ group: 'other' },
					el( MediaReplaceFlow, {
						mediaId: attributes.attachmentId,
						allowedTypes: TYPES,
						accept: 'audio/*,video/*',
						onSelect,
						name: __( 'Replace', 'audio-transcriber' ),
					} )
				),
				el( ServerSideRender, { block: NAME, attributes: { attachmentId: attributes.attachmentId } } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
