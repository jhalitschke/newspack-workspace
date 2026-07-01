/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Link, Stack, Text } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * Newspack breadcrumb trail.
 *
 * Data-driven: the last item is the current page (the single h1, never a link);
 * every other item renders as a link when it has a `url`, otherwise plain text.
 * A plain `<a href>` drives both full admin URLs and hash routes, so the
 * component needs no router context.
 *
 * @param {Object} props
 * @param {Array}  props.items Trail items: `{ label, url? }`.
 * @return {JSX.Element|null} Breadcrumbs component.
 */
const Breadcrumbs = ( { items = [] } ) => {
	if ( ! items.length ) {
		return null;
	}

	const preceding = items.slice( 0, -1 );
	const last = items[ items.length - 1 ];

	return (
		<nav aria-label={ __( 'Breadcrumbs', 'newspack-plugin' ) }>
			<Stack render={ <ul /> } direction="row" align="center" className="newspack-breadcrumbs__list">
				{ preceding.map( ( item, index ) => (
					<li key={ item.url || index }>
						{ item.url ? (
							<Text variant="body-lg" render={ <Link tone="neutral" render={ <a href={ item.url } /> } /> }>
								{ item.label }
							</Text>
						) : (
							<Text variant="body-lg">{ item.label }</Text>
						) }
						<Text variant="body-lg" aria-hidden="true" className="newspack-breadcrumbs__separator">
							/
						</Text>
					</li>
				) ) }
				{ last && last.label && (
					<li>
						<Text variant="heading-lg" render={ <h1 /> } className="newspack-breadcrumbs__current">
							{ last.label }
						</Text>
					</li>
				) }
			</Stack>
		</nav>
	);
};

export default Breadcrumbs;
