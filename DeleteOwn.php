<?php

/**
 * Implements Extension:DeleteOwn for the MediaWiki software.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @ingroup Extensions
 * @file
 */

$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'DeleteOwn',
	'description' => 'Allows users to delete young pages they authored',
	'version' => 1.0,
	'author' => 'Tyler Romeo <tylerromeo@gmail.com>',
	'url' => 'https://www.mediawiki.org/wiki/Extension:DeleteOwn'
);

/**
 * The expiry for when a page can no longer be deleted by its author.
 *
 * Can be a single integer value, which is applied to all pages,
 * or can be an array of namespaces mapped to individual expiry values.
 * An expiry of 0 (or not specifying a namespace key) disables deletion
 * and an expiry of INF disables the expiry.
 */
$wgDeleteOwnExpiry = INF;

$wgAvailableRights[] = 'deleteown';

Hooks::register( 'TitleQuickPermissions',
	/**
	 * Check if a user can delete a page they authored but has not
	 * been edited by anybody else and is younger than a threshold.
	 *
	 * @param Title $title Title being accessed
	 * @param User $user User performing the action
	 * @param string $action The action being performed
	 * @param array &$errors Permissions errors to be returned
	 * @param bool $doExpensiveQueries Whether to do expensive DB queries
	 * @return bool False (to stop permissions checks) if user is allowed to delete
	 */
	function( Title $title, User $user, $action, array &$errors, $doExpensiveQueries ) {
		global $wgDeleteOwnExpiry;

		// If not on the delete action, or if the user can delete normally, return.
		// Also, if the user doesn't have the deleteown permissions, none of this is applicable.
		if ( $action !== 'delete' || $user->isAllowed( 'delete' ) || !$user->isAllowed( 'deleteown' ) ) {
			return true;
		}

		// Determine an expiry based on the namespace.
		$ns = $title->getNamespace();
		if ( !is_array( $wgDeleteOwnExpiry ) ) {
			// Non-array expiry, means apply this expiry to all namespaces.
			$expiry = $wgDeleteOwnExpiry;
		} elseif ( array_key_exists( $ns, $wgDeleteOwnExpiry ) ) {
			// Namespace-specific expiry exists
			$expiry = $wgDeleteOwnExpiry[$ns];
		} else {
			// Couldn't find an expiry, so disable.
			$expiry = INF;
		}

		// First check if the user is the author.
		$firstRevision = $title->getFirstRevision();
		if ( $firstRevision->getUser() !== $user->getId() ) {
			return true;
		}

		// Then check if the article is young enough to qualify for deleteown.
		$creationTime = new MWTimestamp( $firstRevision->getTimestamp() );
		if ( $creationTime->getTimestamp() + $expiry < time() ) {
			return true;
		}

		// Bail out here if we're not doing expensive queries.
		if ( !$doExpensiveQueries ) {
			return false;
		}

		$dbr = wfGetDB( DB_SLAVE );

		// Only bother changing the expiry if different namespaces have different expiries.
		if ( is_array( $wgDeleteOwnExpiry ) ) {
			// Check if the page was ever moved to a different namespace.
			$previousNs = $dbr->select(
				'logging',
				'log_namespace',
				array(
					'log_type' => 'move',
					'log_page' => $title->getArticleId(),
					$dbr->addIdentifierQuotes( 'log_namespace' ) . ' != ' . $dbr->addQuotes( $title->getNamespace() )
				),
				'hook-DeleteOwn',
				array( 'DISTINCT' )
			);

			// If the page was moved, use the lowest expiry that isn't disabled.
			// That way if a user moves a page, they will still be bound by the original limit.
			foreach ( $previousNs as $row ) {
				$ns = $row->log_namespace;
				if ( isset( $wgDeleteOwnExpiry[$ns] ) && $wgDeleteOwnExpiry[$ns] < $expiry ) {
					// More restrictive expiry.
					$expiry = $wgDeleteOwnExpiry[$ns];
				}
			}

			// Check the expiry again with its new value.
			if ( $creationTime->getTimestamp() + $expiry < time() ) {
				return true;
			}
		}

		// Check if anybody else other than bots have made
		// non-minor edits to the page.
		$botGroups = User::getGroupsWithPermission( 'bot' );
		if ( !$botGroups ) {
			// No need to do complicated join if there are no bot groups.
			$hasOtherAuthors = (bool)$dbr->selectField(
				'revision',
				'rev_user',
				array(
					'rev_page' => $title->getArticleId(),
					$dbr->addIdentifierQuotes( 'rev_user_text' ) . ' != ' . $dbr->addQuotes( $user->getName() ),
					'rev_minor_edit' => 0,
				),
				'hook-DeleteOwn'
			);
		} else {
			$hasOtherAuthors = (bool)$dbr->select(
				array( 'revision', 'user_groups' ),
				array(
					'rev_user',
					'COUNT(' . $dbr->addIdentifierQuotes( 'ug_group' ) . ')'
				),
				array(
					'rev_page' => $title->getArticleId(),
					$dbr->addIdentifierQuotes( 'rev_user_text' ) . ' != ' . $dbr->addQuotes( $user->getName() ),
					'rev_minor_edit' => 0,
				),
				'hook-DeleteOwn',
				array(
					'LIMIT' => 1,
					'GROUP BY' => 'rev_user',
					'HAVING' => array(
						'COUNT(' . $dbr->addIdentifierQuotes( 'ug_group' ) . ')' => 0
					)
				),
				array(
					'user_groups' => array( 'LEFT JOIN', array(
						$dbr->addIdentifierQuotes( 'ug_user' ) . '=' . $dbr->addIdentifierQuotes( 'rev_user' ),
						'ug_group' => User::getGroupsWithPermission( 'bot' ),
					) )
				)
			)->numRows();
		}

		if ( $hasOtherAuthors ) {
			return true;
		}

		return false;
	}
);
