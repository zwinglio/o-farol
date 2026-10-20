/**
 * Bloco "Fonte" — editor.
 *
 * Cole um link (X, Bluesky, Mastodon, YouTube, Vimeo, TikTok ou qualquer site):
 * o servidor busca e guarda uma cópia (POST /farol-fontes/v1/resolve) e o bloco
 * mostra a prévia renderizada pelo próprio PHP (ServerSideRender), igual ao site.
 */
import { registerBlockType, createBlock } from '@wordpress/blocks';
import {
	useBlockProps,
	BlockControls,
	InspectorControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	Placeholder,
	Button,
	TextControl,
	TextareaControl,
	ToolbarGroup,
	ToolbarButton,
	PanelBody,
	Spinner,
	Notice,
	Disabled,
	ExternalLink,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import apiFetch from '@wordpress/api-fetch';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import metadata from '../block.json';

const isUrl = ( text ) => /^https?:\/\/\S+$/i.test( text.trim() );

const MODES = {
	post: { label: 'Post', icon: 'format-status' },
	video: { label: 'Vídeo', icon: 'video-alt3' },
	card: { label: 'Cartão', icon: 'id' },
};
const MODES_BY_KIND = { video: [ 'video', 'card' ], post: [ 'post', 'card' ], page: [ 'card' ] };

/** Cópia mínima para quando a busca falha e o editor prefere preencher à mão. */
const manualSource = ( url ) => {
	let host = url;
	try {
		host = new URL( url ).hostname.replace( /^www\./, '' );
	} catch ( e ) {}
	return {
		platform: 'web',
		provider: host,
		kind: 'page',
		url,
		title: '',
		text: '',
		date: '',
		duration: 0,
		author: { name: '', handle: '', url: '', avatar: null },
		image: null,
		embed: null,
		site: { name: host, icon: null },
		fetched: '',
		manual: true,
	};
};

function Edit( { attributes, setAttributes, clientId } ) {
	const { url, mode, source, caption } = attributes;
	const [ draft, setDraft ] = useState( url );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ editingUrl, setEditingUrl ] = useState( false );
	const postId = useSelect( ( select ) => select( 'core/editor' )?.getCurrentPostId(), [] );
	const blockProps = useBlockProps( { className: 'farol-fonte-editor' } );

	const resolve = async ( target ) => {
		const value = ( target || '' ).trim();
		if ( ! isUrl( value ) ) {
			setError( 'Cole um link completo, começando com https://' );
			return;
		}
		setLoading( true );
		setError( '' );
		try {
			const res = await apiFetch( {
				path: '/farol-fontes/v1/resolve',
				method: 'POST',
				data: { url: value, post_id: postId || 0 },
			} );
			const modes = MODES_BY_KIND[ res.source.kind ] || [ 'card' ];
			setAttributes( {
				url: value,
				source: res.source,
				mode: modes.includes( mode ) ? mode : modes[ 0 ],
			} );
			setEditingUrl( false );
		} catch ( e ) {
			setAttributes( { url: value } );
			setError( e.message || 'Não foi possível buscar este link.' );
		} finally {
			setLoading( false );
		}
	};

	// Bloco criado por colar/transformar: já chega com a URL, busca sozinho.
	useEffect( () => {
		if ( url && ! source ) {
			resolve( url );
		}
	}, [] );

	const updateSource = ( patch ) => setAttributes( { source: { ...source, ...patch } } );
	const updateAuthor = ( patch ) => updateSource( { author: { ...source.author, ...patch } } );

	if ( ! source || editingUrl ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon="admin-links"
					label="Fonte"
					instructions="Cole o link de um post (X, Bluesky, Mastodon), vídeo (YouTube, Vimeo, TikTok) ou matéria de outro veículo. O Farol guarda uma cópia para a citação não sumir se o original for apagado."
				>
					<form
						className="farol-fonte-editor__form"
						onSubmit={ ( e ) => {
							e.preventDefault();
							resolve( draft );
						} }
					>
						<input
							type="url"
							className="components-placeholder__input"
							aria-label="Link da fonte"
							placeholder="https://"
							value={ draft }
							disabled={ loading }
							onChange={ ( e ) => setDraft( e.target.value ) }
						/>
						<Button variant="primary" type="submit" disabled={ loading || ! draft }>
							{ loading ? 'Buscando…' : 'Incorporar' }
						</Button>
						{ editingUrl && (
							<Button variant="tertiary" onClick={ () => setEditingUrl( false ) }>
								Cancelar
							</Button>
						) }
					</form>
					{ loading && <Spinner /> }
					{ error && (
						<div className="farol-fonte-editor__error">
							<Notice status="warning" isDismissible={ false }>
								{ error }
							</Notice>
							{ isUrl( draft ) && (
								<div className="farol-fonte-editor__actions">
									<Button variant="secondary" onClick={ () => resolve( draft ) }>
										Tentar de novo
									</Button>
									<Button
										variant="secondary"
										onClick={ () => {
											setAttributes( { url: draft, source: manualSource( draft ), mode: 'card' } );
											setError( '' );
										} }
									>
										Criar cartão e preencher à mão
									</Button>
								</div>
							) }
						</div>
					) }
				</Placeholder>
			</div>
		);
	}

	const modes = MODES_BY_KIND[ source.kind ] || [ 'card' ];
	const current = modes.includes( mode ) ? mode : modes[ 0 ];

	return (
		<figure { ...blockProps }>
			<BlockControls>
				{ modes.length > 1 && (
					<ToolbarGroup label="Formato">
						{ modes.map( ( m ) => (
							<ToolbarButton
								key={ m }
								icon={ MODES[ m ].icon }
								label={ MODES[ m ].label }
								isPressed={ current === m }
								onClick={ () => setAttributes( { mode: m } ) }
							>
								{ MODES[ m ].label }
							</ToolbarButton>
						) ) }
					</ToolbarGroup>
				) }
				<ToolbarGroup>
					<ToolbarButton
						icon="update"
						label="Buscar de novo (substitui edições manuais)"
						disabled={ loading || source.manual }
						onClick={ () => {
							// eslint-disable-next-line no-alert
							if ( window.confirm( 'Buscar a fonte de novo? Edições manuais da cópia serão substituídas.' ) ) {
								resolve( url );
							}
						} }
					/>
					<ToolbarButton
						icon="edit"
						label="Trocar link"
						onClick={ () => {
							setDraft( url );
							setEditingUrl( true );
						} }
					/>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title="Cópia da fonte">
					<p className="farol-fonte-editor__help">
						Corrija ou complete o que a plataforma não informou. É isto que aparece no site, mesmo se o
						original sair do ar.
					</p>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label="Título"
						value={ source.title || '' }
						onChange={ ( title ) => updateSource( { title } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ source.kind === 'post' ? 'Texto do post' : 'Resumo' }
						rows={ 5 }
						value={ source.text || '' }
						onChange={ ( text ) => updateSource( { text } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label="Autor"
						value={ source.author?.name || '' }
						onChange={ ( name ) => updateAuthor( { name } ) }
					/>
					{ source.kind === 'post' && (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label="Usuário (@)"
							value={ source.author?.handle || '' }
							onChange={ ( handle ) => updateAuthor( { handle: handle.replace( /^@/, '' ) } ) }
						/>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label="Data de publicação"
						type="datetime-local"
						value={ source.date ? ( source.date.length === 10 ? `${ source.date }T00:00` : source.date.slice( 0, 16 ) ) : '' }
						onChange={ ( v ) => updateSource( { date: v ? new Date( v ).toISOString() : '' } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ source.platform === 'web' ? 'Veículo / site' : 'Plataforma' }
						value={ source.platform === 'web' ? source.site?.name || '' : source.provider || '' }
						onChange={ ( name ) =>
							source.platform === 'web'
								? updateSource( { provider: name, site: { ...source.site, name } } )
								: updateSource( { provider: name } )
						}
					/>
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ source.image?.id }
							onSelect={ ( m ) =>
								updateSource( {
									image: { id: m.id, url: m.url, width: m.width, height: m.height, alt: m.alt || '' },
								} )
							}
							render={ ( { open } ) => (
								<div className="farol-fonte-editor__image">
									<p className="farol-fonte-editor__label">Imagem</p>
									{ source.image?.url && <img src={ source.image.url } alt="" /> }
									<Button variant="secondary" size="compact" onClick={ open }>
										{ source.image ? 'Trocar imagem' : 'Escolher imagem' }
									</Button>
									{ source.image && (
										<Button variant="link" isDestructive onClick={ () => updateSource( { image: null } ) }>
											Remover
										</Button>
									) }
								</div>
							) }
						/>
					</MediaUploadCheck>
				</PanelBody>
				<PanelBody title="Origem" initialOpen={ false }>
					<p>
						<ExternalLink href={ source.url }>Abrir original</ExternalLink>
					</p>
					<p className="farol-fonte-editor__help">
						{ source.manual
							? 'Cartão preenchido à mão.'
							: `Cópia feita em ${ new Date( source.fetched ).toLocaleString( 'pt-BR' ) }.` }
					</p>
				</PanelBody>
			</InspectorControls>

			{ loading ? (
				<Placeholder icon="admin-links" label="Fonte">
					<Spinner /> Buscando de novo…
				</Placeholder>
			) : (
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ { url, mode: current, source } }
						httpMethod="POST"
					/>
				</Disabled>
			) }

			<RichText
				tagName="figcaption"
				className="farol-fonte-editor__caption"
				aria-label="Legenda da fonte"
				placeholder="Contexto (opcional): por que esta fonte importa"
				value={ caption }
				onChange={ ( value ) => setAttributes( { caption: value } ) }
				allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
			/>
		</figure>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	// Bloco dinâmico: o HTML vem do PHP (includes/render.php).
	save: () => null,
	transforms: {
		from: [
			{
				// Colar um link sozinho num parágrafo vazio: plataformas viram post/vídeo,
				// qualquer outro site vira cartão. Para link simples, transforme em Parágrafo.
				type: 'raw',
				priority: 1,
				isMatch: ( node ) => node.nodeName === 'P' && isUrl( node.textContent || '' ),
				transform: ( node ) => createBlock( metadata.name, { url: node.textContent.trim() } ),
			},
			{
				type: 'block',
				blocks: [ 'core/embed' ],
				isMatch: ( { url } ) => !! url,
				transform: ( { url, caption } ) => createBlock( metadata.name, { url, caption: caption || '' } ),
			},
			{
				// Parágrafo que é só um link (ou só uma URL) → Fonte.
				type: 'block',
				blocks: [ 'core/paragraph' ],
				isMatch: ( { content } ) => {
					const text = String( content || '' ).trim();
					return isUrl( text.replace( /<[^>]+>/g, '' ) ) || /^<a [^>]*href="https?:[^"]+"[^>]*>[^<]*<\/a>$/.test( text );
				},
				transform: ( { content } ) => {
					const text = String( content ).trim();
					const href = ( text.match( /href="([^"]+)"/ ) || [] )[ 1 ] || text.replace( /<[^>]+>/g, '' );
					return createBlock( metadata.name, { url: href } );
				},
			},
		],
		to: [
			{
				type: 'block',
				blocks: [ 'core/paragraph' ],
				transform: ( { url, source } ) =>
					createBlock( 'core/paragraph', {
						content: `<a href="${ url }">${ source?.title || source?.author?.name || url }</a>`,
					} ),
			},
		],
	},
} );
