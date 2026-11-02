import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import ChartEditor from './ChartEditor';
import ChartsList from './ChartsList';
import Dashboard from './Dashboard';
import QuizTab from './QuizTab';
import Returns from './Returns';
import SettingsTab from './SettingsTab';
import Templates from './Templates';
import { AppContext } from './util';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'dashboard' },
	{ id: 'charts', label: __( 'Size charts', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'columns' },
	{ id: 'templates', label: __( 'Templates', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'copy' },
	{ id: 'quiz', label: __( 'Fit quiz', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'users' },
	{ id: 'returns', label: __( 'Returns & learning', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'refresh' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-size-guide-for-woocommerce' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute( NAV.map( ( n ) => n.id ), 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ measures, setMeasures ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const load = () => {
		setError( null );
		Promise.all( [ request( 'settings' ), request( 'measures' ) ] )
			.then( ( [ s, m ] ) => {
				setSettings( s );
				setMeasures( m );
			} )
			.catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const ctx = useMemo( () => ( { settings, save, measures, refreshUsage: () => setMeter( ( n ) => n + 1 ) } ), [ settings, measures ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const chartId = route.id === 'charts' && route.params[ 0 ] ? Number( route.params[ 0 ] ) : 0;
	const screens = {
		dashboard: () => <Dashboard navigate={ navigate } />,
		charts: () => ( chartId ? <ChartEditor key={ chartId } id={ chartId } openAi={ route.params[ 1 ] === 'ai' } navigate={ navigate } /> : <ChartsList navigate={ navigate } /> ),
		templates: () => <Templates navigate={ navigate } />,
		quiz: () => <QuizTab />,
		returns: () => <Returns />,
		settings: () => <SettingsTab />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-size-guide-for-woocommerce' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-size-guide-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-size-guide-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppContext.Provider value={ ctx }>
			<AppShell
				name={ config.name || 'DEV3 AI Size Guide & Fit Advisor for WooCommerce' }
				version={ config.version }
				icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
				nav={ NAV }
				active={ route.id }
				actions={ <UsageMeter compact refreshKey={ meter } /> }
				footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-size-guide-for-woocommerce' ) }
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
				{ settings && screens[ route.id ]() }
			</AppShell>
		</AppContext.Provider>
	);
}
