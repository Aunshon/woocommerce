/**
 * External dependencies
 */
import { execSync } from 'node:child_process';
import path from 'node:path';

/**
 * Internal dependencies
 */
import { CIConfig, parseCIConfig } from './config';
import { loadPackage } from './package-file';

/**
 * A node in the project dependency graph.
 */
export interface ProjectNode {
	name: string;
	path: string;
	ciConfig?: CIConfig;
	dependencies: ProjectNode[];
}

/**
 * Builds a dependency graph of all projects in the monorepo and returns the root node.
 */
export function buildProjectGraph(): ProjectNode {
	// Get the root of the monorepo.
	const monorepoRoot = path.join(
		execSync( 'pnpm -w root', { encoding: 'utf-8' } ),
		'..'
	);

	// PNPM provides us with a flat list of all projects
	// in the workspace and their dependencies.
	const workspace = JSON.parse(
		execSync( 'pnpm -r list --only-projects --json', { encoding: 'utf-8' } )
	);

	// Start by building an object containing all of the nodes keyed by their project name.
	// This will let us link them together quickly by iterating through the list of
	// dependencies and adding the applicable nodes.
	const nodes: { [ name: string ]: ProjectNode } = {};
	let rootNode;
	for ( const project of workspace ) {
		// Use a relative path to the project so that it's easier for us to work with
		const projectPath = project.path.replace(
			new RegExp(
				`^${ monorepoRoot.replace( /\\/g, '\\\\' ) }${ path.sep }?`
			),
			''
		);

		const packageFile = loadPackage(
			path.join( project.path, 'package.json' )
		);

		const ciConfig = parseCIConfig( packageFile );

		const node = {
			name: project.name,
			path: projectPath,
			ciConfig,
			dependencies: [],
		};

		// The first entry that `pnpm list` returns is the workspace root.
		// This will be the root node of our graph.
		if ( ! rootNode ) {
			rootNode = node;
		}

		nodes[ project.name ] = node;
	}

	// One thing to keep in mind is that, technically, our dependency graph has multiple roots.
	// Each package that has no dependencies is a "root", however, for simplicity, we will
	// add these root packages under the monorepo root in order to have a clean graph.
	// Since the monorepo root has no CI config this won't cause any problems.
	// Track this by recording all of the dependencies and removing them
	// from the rootless list if they are added as a dependency.
	const rootlessDependencies = workspace.map( ( project ) => project.name );

	// Now we can scan through all of the nodes and hook them up to their respective dependency nodes.
	for ( const project of workspace ) {
		const node = nodes[ project.name ];
		if ( project.dependencies ) {
			for ( const dependency in project.dependencies ) {
				node.dependencies.push( nodes[ dependency ] );
			}
		}
		if ( project.devDependencies ) {
			for ( const dependency in project.devDependencies ) {
				node.dependencies.push( nodes[ dependency ] );
			}
		}

		// Mark any dependencies that have a dependent as not being rootless.
		// A rootless dependency is one that nothing depends on.
		for ( const dependency of node.dependencies ) {
			const index = rootlessDependencies.indexOf( dependency.name );
			if ( index > -1 ) {
				rootlessDependencies.splice( index, 1 );
			}
		}
	}

	// Track the rootless dependencies now that we have them.
	for ( const rootless of rootlessDependencies ) {
		// Don't add the root node as a dependency of itself.
		if ( rootless === rootNode.name ) {
			continue;
		}

		rootNode.dependencies.push( nodes[ rootless ] );
	}

	return rootNode;
}
