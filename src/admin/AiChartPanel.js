import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, ErrorNotice, Inline, Notice, Select, Stack, Tabs, Textarea, useToast } from '../core3/ui';
import { PreviewTable } from './ChartPreview';
import TermPicker from './TermPicker';
import { PROFILES, useApp } from './util';

const MAX_IMAGE = 4 * 1024 * 1024;

/**
 * Reads a File into a data: URL.
 *
 * @param {File} file File.
 * @return {Promise<string>} Data URL.
 */
export function readFile( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new window.FileReader();
		reader.onload = () => resolve( String( reader.result ) );
		reader.onerror = () => reject( reader.error );
		reader.readAsDataURL( file );
	} );
}

/**
 * Chart draft review: preview of the drafted tables, then replace / add / regenerate / reject.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.result       { draft, notes, model, tokens, cached, warnings, source }.
 * @param {Function} props.onAccept     ( draft, mode: replace|append ) => void.
 * @param {Function} props.onReject     Reject.
 * @param {Function} props.onRegenerate Regenerate (AI only).
 * @param {boolean}  props.busy         Regenerating.
 * @return {Element} Review.
 */
export function ChartDraft( { result, onAccept, onReject, onRegenerate, busy } ) {
	const { draft } = result;
	const ai = result.source !== 'parser';
	return (
		<div className="dev3sg-ui-draft dev3sg-a-draft">
			<div className="dev3sg-ui-draft__head">
				<div>
					<p className="dev3sg-ui-draft__title">{ ai ? __( 'DEV3 AI draft', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Imported table', 'dev3-ai-size-guide-for-woocommerce' ) }</p>
					<p className="dev3sg-ui-draft__meta">
						{ __( 'Not saved yet', 'dev3-ai-size-guide-for-woocommerce' ) }
						{ result.model ? ` · ${ result.model }` : '' }
						{ result.tokens ? ` · ${ sprintf( /* translators: %d: tokens. */ __( '%d tokens', 'dev3-ai-size-guide-for-woocommerce' ), result.tokens ) }` : '' }
						{ result.cached ? ` · ${ __( 'cached', 'dev3-ai-size-guide-for-woocommerce' ) }` : '' }
					</p>
				</div>
				{ draft.profile && <Badge tone="brand">{ ( PROFILES.find( ( p ) => p.value === draft.profile ) || {} ).label }</Badge> }
			</div>
			{ ( result.notes || ( result.warnings || [] ).length > 0 ) && (
				<Notice tone="info" title={ __( 'Check before accepting', 'dev3-ai-size-guide-for-woocommerce' ) }>
					{ [ result.notes, ...( result.warnings || [] ) ].filter( Boolean ).join( ' ' ) }
				</Notice>
			) }
			<div className="dev3sg-guide dev3sg-a-draft__tables" data-unit="metric">
				{ draft.tables.map( ( t, i ) => (
					<div key={ i } className="dev3sg-a-draft__table">
						<p className="dev3sg-a-draft__ttl">
							{ t.title || __( 'Table', 'dev3-ai-size-guide-for-woocommerce' ) } · { sprintf( /* translators: %d: rows. */ __( '%d sizes', 'dev3-ai-size-guide-for-woocommerce' ), t.rows.length ) }
						</p>
						<PreviewTable table={ t } />
					</div>
				) ) }
				{ draft.fit_note && <p className="dev3sg-note">ⓘ { draft.fit_note }</p> }
			</div>
			<div className="dev3sg-ui-draft__foot">
				<Inline gap={ 8 }>
					<Button variant="primary" icon="check" onClick={ () => onAccept( draft, 'replace' ) }>
						{ __( 'Use these tables', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
					<Button icon="plus" onClick={ () => onAccept( draft, 'append' ) }>
						{ __( 'Add as extra tables', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
					{ ai && onRegenerate && (
						<Button variant="ghost" icon="refresh" loading={ busy } onClick={ onRegenerate }>
							{ __( 'Regenerate', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					) }
					<Button variant="ghost" icon="x" onClick={ onReject }>
						{ __( 'Reject', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Button>
				</Inline>
			</div>
		</div>
	);
}

/**
 * "Generate with DEV3 AI" card: from product data, pasted text (also a non-AI parser) or a photo.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.chart    Current chart.
 * @param {Function} props.onAccept ( draft, mode ) => void.
 * @return {Element} Card.
 */
export default function AiChartPanel( { chart, onAccept } ) {
	const { settings, refreshUsage } = useApp();
	const toast = useToast();
	const [ source, setSource ] = useState( 'product' );
	const [ product, setProduct ] = useState( [] );
	const [ profile, setProfile ] = useState( chart.profile || '' );
	const [ unit, setUnit ] = useState( 'auto' );
	const [ notes, setNotes ] = useState( '' );
	const [ text, setText ] = useState( '' );
	const [ image, setImage ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ result, setResult ] = useState( null );
	const aiReady = settings.api_key_set && settings.ai_enabled !== false;

	const body = () => {
		if ( source === 'text' ) {
			return { source, text, unit };
		}
		if ( source === 'image' ) {
			return { source, data: image ? image.data : '', mime: image ? image.mime : '', unit };
		}
		return { source, product_id: product[ 0 ] ? product[ 0 ].id : 0, profile, unit: unit === 'in' ? 'in' : 'cm', notes };
	};

	const generate = () => {
		setBusy( true );
		setError( null );
		request( 'ai/chart', { method: 'POST', data: body() } )
			.then( ( r ) => setResult( { ...r, source: 'ai' } ) )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				refreshUsage();
			} );
	};

	const parse = () => {
		setBusy( true );
		setError( null );
		request( 'parse', { method: 'POST', data: { text, unit } } )
			.then( ( r ) => setResult( { draft: { tables: [ r.table ], profile: '', fit_note: '' }, warnings: r.warnings, source: 'parser' } ) )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	const pickImage = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		if ( file.size > MAX_IMAGE ) {
			toast.error( __( 'The image is larger than 4 MB.', 'dev3-ai-size-guide-for-woocommerce' ) );
			return;
		}
		readFile( file ).then( ( url ) => setImage( { data: url, mime: file.type, name: file.name, url } ) );
	};

	const accept = ( draft, mode ) => {
		onAccept( draft, mode );
		setResult( null );
		toast.success( __( 'Added to the editor. Save the chart to keep it.', 'dev3-ai-size-guide-for-woocommerce' ) );
	};

	const ready = ( source === 'product' && product.length > 0 ) || ( source === 'text' && text.trim().length > 9 ) || ( source === 'image' && image );

	return (
		<Card tone="ai" title={ __( 'Build the chart with DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Draft a complete chart from a product, a pasted supplier chart or a photo. You review it before anything is saved.', 'dev3-ai-size-guide-for-woocommerce' ) }>
			<Stack gap={ 16 }>
				<Tabs
					variant="pills"
					label={ __( 'Source', 'dev3-ai-size-guide-for-woocommerce' ) }
					value={ source }
					onChange={ ( v ) => {
						setSource( v );
						setError( null );
					} }
					tabs={ [
						{ id: 'product', label: __( 'From product data', 'dev3-ai-size-guide-for-woocommerce' ) },
						{ id: 'text', label: __( 'Paste a chart', 'dev3-ai-size-guide-for-woocommerce' ) },
						{ id: 'image', label: __( 'Photo or screenshot', 'dev3-ai-size-guide-for-woocommerce' ) },
					] }
				/>
				{ source === 'product' && (
					<div className="dev3sg-a-formrow">
						<TermPicker single type="product" label={ __( 'Product', 'dev3-ai-size-guide-for-woocommerce' ) } value={ product } onChange={ setProduct } help={ __( 'Its title, categories, description and size options are sent to Google Gemini.', 'dev3-ai-size-guide-for-woocommerce' ) } />
						<Select label={ __( 'Body profile', 'dev3-ai-size-guide-for-woocommerce' ) } value={ profile } onChange={ setProfile } options={ [ { value: '', label: __( 'Let DEV3 AI decide', 'dev3-ai-size-guide-for-woocommerce' ) }, ...PROFILES ] } />
						<Textarea label={ __( 'Notes for DEV3 AI (optional)', 'dev3-ai-size-guide-for-woocommerce' ) } value={ notes } onChange={ setNotes } rows={ 2 } maxLength={ 500 } placeholder={ __( 'e.g. Italian brand, slim fit, sizes 44-56', 'dev3-ai-size-guide-for-woocommerce' ) } />
					</div>
				) }
				{ source === 'text' && (
					<Textarea
						label={ __( 'Pasted size chart', 'dev3-ai-size-guide-for-woocommerce' ) }
						value={ text }
						onChange={ setText }
						rows={ 7 }
						maxLength={ 12000 }
						help={ __( 'Copy a table from a supplier website, PDF or spreadsheet. Clean tables can be read without AI.', 'dev3-ai-size-guide-for-woocommerce' ) }
						placeholder={ 'Size\tChest (cm)\tWaist (cm)\nS\t86-91\t71-76\nM\t91-97\t76-81' }
					/>
				) }
				{ source === 'image' && (
					<div className="dev3sg-a-upload">
						<label className="dev3sg-a-upload__drop">
							<input type="file" accept="image/jpeg,image/png,image/webp" onChange={ pickImage } />
							{ image ? <img src={ image.url } alt={ __( 'Selected size chart', 'dev3-ai-size-guide-for-woocommerce' ) } /> : <span>{ __( 'Choose a JPG, PNG or WebP (max 4 MB)', 'dev3-ai-size-guide-for-woocommerce' ) }</span> }
						</label>
						{ image && <p className="dev3sg-a-muted">{ image.name }</p> }
					</div>
				) }
				{ source !== 'product' && (
					<Select
						label={ __( 'Unit used in the source', 'dev3-ai-size-guide-for-woocommerce' ) }
						value={ unit }
						onChange={ setUnit }
						options={ [
							{ value: 'auto', label: __( 'Detect automatically', 'dev3-ai-size-guide-for-woocommerce' ) },
							{ value: 'cm', label: __( 'Centimetres', 'dev3-ai-size-guide-for-woocommerce' ) },
							{ value: 'in', label: __( 'Inches', 'dev3-ai-size-guide-for-woocommerce' ) },
						] }
					/>
				) }
				{ ! aiReady && (
					<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-size-guide-for-woocommerce' ) } actions={ <Button size="sm" href="#/api">{ __( 'Add your API key', 'dev3-ai-size-guide-for-woocommerce' ) }</Button> }>
						{ source === 'text' ? __( 'Without a key you can still read clean pasted tables.', 'dev3-ai-size-guide-for-woocommerce' ) : __( 'Add a Google Gemini API key to draft charts with AI. Templates and the editor work without it.', 'dev3-ai-size-guide-for-woocommerce' ) }
					</Notice>
				) }
				<ErrorNotice error={ error } onRetry={ error && error.retryable ? generate : undefined } onDismiss={ () => setError( null ) } />
				<Inline gap={ 8 }>
					<AiButton loading={ busy } disabled={ ! ready || ! aiReady } onClick={ generate } />
					{ source === 'text' && (
						<Button icon="list" disabled={ ! ready || busy } onClick={ parse }>
							{ __( 'Read table (no AI)', 'dev3-ai-size-guide-for-woocommerce' ) }
						</Button>
					) }
				</Inline>
				{ result && <ChartDraft result={ result } busy={ busy } onAccept={ accept } onReject={ () => setResult( null ) } onRegenerate={ generate } /> }
			</Stack>
		</Card>
	);
}
