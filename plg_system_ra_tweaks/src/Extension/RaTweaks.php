<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.ra_tweaks
 */

namespace Ramblerseastcheshire\Plugin\System\RaTweaks\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Menu\AdministratorMenuItem;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

/**
 * Colours changed Ramblers walk items in the final rendered page output.
 */
final class RaTweaks extends CMSPlugin implements SubscriberInterface
{
	/**
	 * @var CMSApplicationInterface
	 */
	protected $app;

	private ?int $extensionId = null;

	public static function getSubscribedEvents(): array
	{
		return [
			'onPreprocessMenuItems' => 'onPreprocessMenuItems',
			'onAfterRender' => 'onAfterRender',
		];
	}

	/**
	 * Add a direct administrator menu link to this plugin's settings.
	 *
	 * Joomla 4.4 dispatches this event with legacy positional arguments, while
	 * Joomla 5 dispatches a PreprocessMenuItemsEvent object.
	 *
	 * @param mixed $eventOrContext
	 * @param AdministratorMenuItem[]|null $items
	 */
	public function onPreprocessMenuItems($eventOrContext, ?array &$items = null): void
	{
		$context = $this->menuEventContext($eventOrContext);

		if (!$this->app->isClient('administrator') || $context !== 'com_menus.administrator.module') {
			return;
		}

		$menuItems = $this->menuEventItems($eventOrContext, $items);

		if (!$this->isRootAdminMenu($menuItems) || $this->hasRaTweaksMenuItem($menuItems)) {
			return;
		}

		$extensionId = $this->getExtensionId();

		if ($extensionId === null) {
			return;
		}

		$componentsMenu = $this->componentsMenuItem($menuItems);

		if (!$componentsMenu instanceof AdministratorMenuItem) {
			return;
		}

		$componentsMenu->addChild($this->raTweaksMenuItem($extensionId));

		if (is_object($eventOrContext) && method_exists($eventOrContext, 'updateItems')) {
			$eventOrContext->updateItems($menuItems);

			return;
		}

		if (is_object($eventOrContext) && method_exists($eventOrContext, 'setArgument')) {
			$eventOrContext->setArgument('subject', $menuItems);
			$eventOrContext->setArgument(1, $menuItems);
		}

		$items = $menuItems;
	}

	private function menuEventContext($eventOrContext): string
	{
		if (is_object($eventOrContext) && method_exists($eventOrContext, 'getContext')) {
			return (string) $eventOrContext->getContext();
		}

		if (is_object($eventOrContext) && method_exists($eventOrContext, 'getArgument')) {
			return (string) $eventOrContext->getArgument('context', $eventOrContext->getArgument(0, ''));
		}

		return (string) $eventOrContext;
	}

	/**
	 * @param mixed $eventOrContext
	 * @param AdministratorMenuItem[]|null $items
	 *
	 * @return AdministratorMenuItem[]
	 */
	private function menuEventItems($eventOrContext, ?array $items): array
	{
		if (is_object($eventOrContext) && method_exists($eventOrContext, 'getItems')) {
			return $eventOrContext->getItems();
		}

		if (is_object($eventOrContext) && method_exists($eventOrContext, 'getArgument')) {
			$eventItems = $eventOrContext->getArgument('subject', $eventOrContext->getArgument(1, []));

			return is_array($eventItems) ? $eventItems : [];
		}

		return $items ?? [];
	}

	/**
	 * @param AdministratorMenuItem[] $items
	 */
	private function isRootAdminMenu(array $items): bool
	{
		foreach ($items as $item) {
			if (($item->element ?? '') === 'com_cpanel' && ($item->link ?? '') === 'index.php') {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param AdministratorMenuItem[] $items
	 */
	private function hasRaTweaksMenuItem(array $items): bool
	{
		foreach ($items as $item) {
			if (($item->link ?? '') === $this->raTweaksMenuLink()) {
				return true;
			}

			if ($item->hasChildren() && $this->hasRaTweaksMenuItem($item->getChildren())) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param AdministratorMenuItem[] $items
	 */
	private function componentsMenuItem(array $items): ?AdministratorMenuItem
	{
		foreach ($items as $item) {
			if (($item->type ?? '') === 'container' && ($item->title ?? '') === 'MOD_MENU_COMPONENTS') {
				return $item;
			}
		}

		return null;
	}

	private function raTweaksMenuItem(int $extensionId): AdministratorMenuItem
	{
		return new AdministratorMenuItem([
			'title'   => 'RA Tweaks',
			'type'    => 'component',
			'element' => 'com_plugins',
			'link'    => 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId,
			'class'   => 'class:sliders-h',
		]);
	}

	private function getExtensionId(): ?int
	{
		if ($this->extensionId !== null) {
			return $this->extensionId;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('element') . ' = ' . $db->quote('ra_tweaks'));

		$db->setQuery($query);
		$extensionId = (int) $db->loadResult();

		$this->extensionId = $extensionId > 0 ? $extensionId : null;

		return $this->extensionId;
	}

	private function raTweaksMenuLink(): string
	{
		$extensionId = $this->getExtensionId();

		return $extensionId === null
			? ''
			: 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId;
	}

	public function onAfterRender(): void
	{
		if (!$this->app->isClient('site')) {
			return;
		}

		$body = $this->app->getBody();

		if ($body === '' || stripos($body, '<html') === false) {
			return;
		}

		$marker = (string) $this->params->get('marker', '***');
		$colour = $this->normaliseColour((string) $this->params->get('colour', '#F08050'));
		$cancelTerms = $this->normaliseList((string) $this->params->get('cancel_terms', 'cancelled,canceled'));
		$alignGradeIcons = (bool) $this->params->get('align_grade_icons', 1);
		$walkTypeLabelling = $this->walkTypeLabellingConfig();
		$diaryIconsEnabled = (bool) $this->params->get('diary_category_icons', 1);

		$needsMarkerPass = $marker !== '' && strpos($body, $marker) !== false;

		if (!$needsMarkerPass && !$diaryIconsEnabled) {
			if ($alignGradeIcons || $walkTypeLabelling['enabled']) {
				$output = $this->injectFrontendScript($body, $marker, $colour, $cancelTerms, $alignGradeIcons, $walkTypeLabelling);

				if ($output !== $body) {
					$this->app->setBody($output);
				}
			}

			return;
		}

		$dom = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if (!$loaded) {
			$output = $this->injectFrontendScript($body, $marker, $colour, $cancelTerms, $alignGradeIcons, $walkTypeLabelling);

			if ($output !== $body) {
				$this->app->setBody($output);
			}

			return;
		}

		$changed = false;

		if ($needsMarkerPass) {
			$changed = $this->applyChangedWalkMarker($dom, $marker, $colour, $cancelTerms);
		}

		if ($diaryIconsEnabled) {
			$changed = $this->applyDiaryCategoryIcons($dom) || $changed;
		}

		$output = $body;

		if ($changed) {
			$output = $dom->saveHTML();

			if (is_string($output)) {
				$output = $this->stripInjectedXmlDeclaration($output);
			}
		}

		$output = $this->injectFrontendScript($output, $marker, $colour, $cancelTerms, $alignGradeIcons, $walkTypeLabelling);

		if ($changed || $output !== $body) {
			$this->app->setBody($output);
		}
	}

	private function applyChangedWalkMarker(\DOMDocument $dom, string $marker, string $colour, array $cancelTerms): bool
	{
		$xpath = new \DOMXPath($dom);
		$textNodes = $xpath->query('//text()[contains(., ' . $this->xpathLiteral($marker) . ')]');
		$changed = false;

		if (!$textNodes instanceof \DOMNodeList) {
			return false;
		}

		foreach (iterator_to_array($textNodes) as $textNode) {
			if (!$textNode instanceof \DOMText || !$textNode->parentNode instanceof \DOMElement) {
				continue;
			}

			if ($this->isIgnoredTextNode($textNode)) {
				continue;
			}

			$container = $this->nearestContainer($textNode->parentNode);

			if ($this->isCancelled($container, $cancelTerms)) {
				continue;
			}

			$changedNode = $this->topLevelNodeWithin($textNode, $container);

			$this->removeMarkerFromTextNode($textNode, $marker);
			$this->prependMarker($container, $marker, $colour);
			$this->applyColourToLeadingWalkText($container, $changedNode, $colour);

			$changed = true;
		}

		return $changed;
	}

	/**
	 * @return array{enabled: bool, strollerMax: float, shortMax: float, mediumMax: float, colours: array<string, string>}
	 */
	private function walkTypeLabellingConfig(): array
	{
		return [
			'enabled' => (bool) $this->params->get('walk_type_labels', 1),
			'strollerMax' => $this->normaliseMiles((string) $this->params->get('stroller_max', '4')),
			'shortMax' => $this->normaliseMiles((string) $this->params->get('short_max', '7.9')),
			'mediumMax' => $this->normaliseMiles((string) $this->params->get('medium_max', '10.9')),
			'colours' => [
				'stroller' => $this->normaliseColour((string) $this->params->get('stroller_colour', '#4CAF50')),
				'short' => $this->normaliseColour((string) $this->params->get('short_colour', '#2196F3')),
				'medium' => $this->normaliseColour((string) $this->params->get('medium_colour', '#FF9800')),
				'long' => $this->normaliseColour((string) $this->params->get('long_colour', '#E53935')),
				'evening' => $this->normaliseColour((string) $this->params->get('evening_colour', '#5E35B1')),
			],
		];
	}

	private function normaliseMiles(string $value): float
	{
		return is_numeric($value) ? (float) $value : 0.0;
	}

	private function normaliseColour(string $colour): string
	{
		$colour = trim($colour);

		if (preg_match('/^#[0-9a-f]{3}([0-9a-f]{3})?$/i', $colour) === 1) {
			return $colour;
		}

		return '#F08050';
	}

	/**
	 * @return string[]
	 */
	private function normaliseList(string $value): array
	{
		$items = preg_split('/[\r\n,]+/', $value) ?: [];
		$items = array_map('trim', $items);
		$items = array_filter($items, static fn ($item) => $item !== '');

		return array_values(array_unique($items));
	}

	private function xpathLiteral(string $value): string
	{
		if (!str_contains($value, '"')) {
			return '"' . $value . '"';
		}

		if (!str_contains($value, "'")) {
			return "'" . $value . "'";
		}

		$parts = explode('"', $value);
		$quoted = array_map(static fn ($part) => '"' . $part . '"', $parts);

		return 'concat(' . implode(', \'"\', ', $quoted) . ')';
	}

	private function isIgnoredTextNode(\DOMText $node): bool
	{
		for ($current = $node->parentNode; $current instanceof \DOMElement; $current = $current->parentNode) {
			if (in_array(strtolower($current->nodeName), ['script', 'style', 'textarea', 'title'], true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Skip walks already marked as cancelled, including struck-through output.
	 *
	 * @param string[] $cancelTerms
	 */
	private function isCancelled(\DOMElement $node, array $cancelTerms): bool
	{
		$container = $this->nearestContainer($node);
		$text = strtolower($container->textContent ?? '');

		foreach ($cancelTerms as $term) {
			if ($term !== '' && str_contains($text, strtolower($term))) {
				return true;
			}
		}

		for ($current = $node; $current instanceof \DOMElement; $current = $current->parentNode) {
			$style = strtolower($current->getAttribute('style'));

			if (str_contains($style, 'line-through') || strtolower($current->nodeName) === 's' || strtolower($current->nodeName) === 'strike') {
				return true;
			}
		}

		return false;
	}

	private function nearestContainer(\DOMElement $node): \DOMElement
	{
		for ($current = $node; $current instanceof \DOMElement; $current = $current->parentNode) {
			$name = strtolower($current->nodeName);

			if (in_array($name, ['li', 'tr', 'p', 'article', 'section', 'div'], true)) {
				return $current;
			}
		}

		return $node;
	}

	private function topLevelNodeWithin(\DOMNode $node, \DOMElement $container): \DOMNode
	{
		$current = $node;

		while ($current->parentNode instanceof \DOMNode && !$current->parentNode->isSameNode($container)) {
			$current = $current->parentNode;
		}

		return $current;
	}

	private function applyColour(\DOMElement $node, string $colour): void
	{
		$style = trim($node->getAttribute('style'));
		$style = preg_replace('/(^|;)\s*color\s*:[^;]*/i', '', $style) ?? '';
		$style = trim($style, " \t\n\r\0\x0B;");

		if ($style !== '') {
			$style .= '; ';
		}

		$node->setAttribute('style', $style . 'color: ' . $colour . ';');
	}

	private function removeMarkerFromTextNode(\DOMText $node, string $marker): void
	{
		$node->nodeValue = preg_replace('/\s*' . preg_quote($marker, '/') . '\s*/', ' ', $node->nodeValue) ?? $node->nodeValue;
		$node->nodeValue = preg_replace('/\s{2,}/', ' ', $node->nodeValue) ?? $node->nodeValue;
		$node->nodeValue = ltrim($node->nodeValue);
	}

	private function prependMarker(\DOMElement $container, string $marker, string $colour): void
	{
		if ($this->hasMarkerBadge($container)) {
			return;
		}

		$firstTextNode = $this->firstMeaningfulTextNode($container);
		$badge = $this->createMarkerBadge($container->ownerDocument, $marker, $colour);

		if ($firstTextNode instanceof \DOMText) {
			$firstTextNode->nodeValue = ltrim($firstTextNode->nodeValue);
			$firstTextNode->parentNode?->insertBefore($badge, $firstTextNode);

			return;
		}

		$container->insertBefore($badge, $container->firstChild);
	}

	private function hasMarkerBadge(\DOMElement $container): bool
	{
		foreach ($container->getElementsByTagName('span') as $span) {
			if ($span instanceof \DOMElement && $span->getAttribute('data-ra_tweaks-marker') === '1') {
				return true;
			}
		}

		return false;
	}

	private function createMarkerBadge(\DOMDocument $document, string $marker, string $colour): \DOMElement
	{
		$badge = $document->createElement('span');
		$badge->setAttribute('data-ra_tweaks-marker', '1');
		$badge->setAttribute('title', 'Walk details changed');
		$badge->setAttribute('aria-label', 'Walk details changed');
		$badge->setAttribute('style', $this->markerBadgeStyle($colour));
		$badge->appendChild($document->createTextNode($marker));

		return $badge;
	}

	private function markerBadgeStyle(string $colour): string
	{
		return 'display: inline-grid; place-items: center; '
			. 'width: 1.45em; height: 1.45em; margin-right: 0.3em; border-radius: 999px; '
			. 'background: ' . $colour . '; color: #fff; font-size: 0.72em; font-weight: 800; '
			. 'line-height: 1; vertical-align: 0.12em; padding-top: 0.12em; box-sizing: border-box;';
	}

	private function applyDiaryCategoryIcons(\DOMDocument $dom): bool
	{
		$xpath = new \DOMXPath($dom);
		$anchors = $xpath->query("//a[contains(@href, 'option=com_ra_events') and contains(@href, 'view=event')]");

		if (!$anchors instanceof \DOMNodeList || $anchors->length === 0) {
			return false;
		}

		$eventIds = [];

		foreach (iterator_to_array($anchors) as $anchor) {
			if (!$anchor instanceof \DOMElement) {
				continue;
			}

			$id = $this->extractEventId((string) $anchor->getAttribute('href'));

			if ($id !== null) {
				$eventIds[$id] = true;
			}
		}

		if ($eventIds === []) {
			return false;
		}

		$types = $this->fetchDiaryEventTypes(array_keys($eventIds));

		if ($types === []) {
			return false;
		}

		$changed = false;

		foreach (iterator_to_array($anchors) as $anchor) {
			if (!$anchor instanceof \DOMElement) {
				continue;
			}

			$id = $this->extractEventId((string) $anchor->getAttribute('href'));
			$eventTypeId = $id !== null ? ($types[$id] ?? null) : null;

			if ($eventTypeId === null) {
				continue;
			}

			$icon = $this->diaryCategoryIcon($eventTypeId);

			if ($icon === null) {
				continue;
			}

			if ($anchor->getAttribute('data-ra_tweaks-diary-icon') === '1') {
				continue;
			}

			$lineNodes = $this->diaryEntryLineNodes($anchor);
			$parent = $lineNodes[0]->parentNode ?? null;

			if (!$parent instanceof \DOMNode) {
				continue;
			}

			$badge = $this->createDiaryCategoryBadge($dom, $icon);
			$wrapper = $dom->createElement('span');
			$wrapper->setAttribute('data-ra_tweaks-diary-row', '1');
			$wrapper->setAttribute('style', $this->diaryRowStyle());
			$wrapper->appendChild($badge);

			$parent->insertBefore($wrapper, $lineNodes[0]);

			foreach ($lineNodes as $node) {
				$wrapper->appendChild($node);
			}

			$trailingBreak = $wrapper->nextSibling;

			if ($trailingBreak instanceof \DOMElement && strtolower($trailingBreak->nodeName) === 'br') {
				$parent->removeChild($trailingBreak);
			}

			$anchor->setAttribute('data-ra_tweaks-diary-icon', '1');
			$changed = true;
		}

		return $changed;
	}

	/**
	 * Diary Dates entries aren't individually wrapped - they're flat text/<br>/<a>
	 * siblings inside one shared module container. Collect the full run of nodes
	 * making up this entry's own line: walk backward through previous siblings to
	 * the nearest element boundary (a <br>, or the module heading for the first
	 * entry), then forward from the anchor to the next element boundary.
	 *
	 * @return \DOMNode[]
	 */
	private function diaryEntryLineNodes(\DOMElement $anchor): array
	{
		$backward = [];
		$node = $anchor;

		while ($node->previousSibling instanceof \DOMNode) {
			$node = $node->previousSibling;

			if ($node instanceof \DOMElement) {
				break;
			}

			$backward[] = $node;
		}

		$nodes = array_merge(array_reverse($backward), [$anchor]);
		$node = $anchor;

		while ($node->nextSibling instanceof \DOMNode) {
			$next = $node->nextSibling;

			if ($next instanceof \DOMElement) {
				break;
			}

			$nodes[] = $next;
			$node = $next;
		}

		return $nodes;
	}

	private function diaryRowStyle(): string
	{
		return 'display: block; padding-left: 36px; text-indent: -36px;';
	}

	private function extractEventId(string $href): ?int
	{
		if (preg_match('/[?&]id=(\d+)/', html_entity_decode($href), $matches) === 1) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, int>
	 */
	private function fetchDiaryEventTypes(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName(['id', 'event_type_id']))
				->from($db->quoteName('#__ra_events'))
				->whereIn($db->quoteName('id'), $ids);

			$db->setQuery($query);
			$rows = $db->loadAssocList();
		} catch (\Throwable $exception) {
			return [];
		}

		$types = [];

		foreach ($rows as $row) {
			$types[(int) $row['id']] = (int) $row['event_type_id'];
		}

		return $types;
	}

	/**
	 * Icons are drawn as inline SVG rather than emoji: colour-emoji glyphs render
	 * inconsistently across browsers/OSes (unpredictable bounding boxes relative to
	 * their nominal font size, causing overflow past the circular badge), while
	 * plain SVG shapes render identically everywhere with no font dependency.
	 *
	 * @return array{colour: string, label: string, shapes: array<int, array{0: string, 1: array<string, string>}>}|null
	 */
	private function diaryCategoryIcon(int $eventTypeId): ?array
	{
		$icons = [
			1 => [
				'colour' => '#607D8B',
				'label' => 'Committee Meeting',
				'shapes' => [
					['rect', ['x' => '6', 'y' => '4', 'width' => '12', 'height' => '16', 'rx' => '2']],
					['rect', ['x' => '9', 'y' => '2', 'width' => '6', 'height' => '4', 'rx' => '1', 'fill' => '#fff', 'stroke' => 'none']],
					['line', ['x1' => '8', 'y1' => '10', 'x2' => '16', 'y2' => '10']],
					['line', ['x1' => '8', 'y1' => '13', 'x2' => '16', 'y2' => '13']],
					['line', ['x1' => '8', 'y1' => '16', 'x2' => '13', 'y2' => '16']],
				],
			],
			2 => [
				'colour' => '#E91E63',
				'label' => 'Social Event',
				'shapes' => [
					['path', ['d' => 'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-4 4v-4H6a2 2 0 0 1-2-2z']],
				],
			],
			3 => [
				'colour' => '#009688',
				'label' => 'Training',
				'shapes' => [
					['circle', ['cx' => '12', 'cy' => '12', 'r' => '9']],
					['polygon', ['points' => '12,6 14,12 12,18 10,12', 'fill' => '#fff', 'stroke' => 'none']],
				],
			],
			4 => [
				'colour' => '#FF5722',
				'label' => 'Weekend Away',
				'shapes' => [
					['rect', ['x' => '4', 'y' => '8', 'width' => '16', 'height' => '12', 'rx' => '2']],
					['path', ['d' => 'M9 8V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2']],
					['line', ['x1' => '4', 'y1' => '13', 'x2' => '20', 'y2' => '13']],
				],
			],
			5 => [
				'colour' => '#3F51B5',
				'label' => 'Coach Trip',
				'shapes' => [
					['rect', ['x' => '3', 'y' => '5', 'width' => '18', 'height' => '11', 'rx' => '2']],
					['line', ['x1' => '3', 'y1' => '11', 'x2' => '21', 'y2' => '11']],
					['circle', ['cx' => '7', 'cy' => '18', 'r' => '1.6', 'fill' => '#fff', 'stroke' => 'none']],
					['circle', ['cx' => '17', 'cy' => '18', 'r' => '1.6', 'fill' => '#fff', 'stroke' => 'none']],
				],
			],
			6 => [
				'colour' => '#4CAF50',
				'label' => 'Themed Walk',
				'shapes' => [
					['ellipse', ['cx' => '9', 'cy' => '7', 'rx' => '2.2', 'ry' => '3.2']],
					['ellipse', ['cx' => '16', 'cy' => '16', 'rx' => '2.2', 'ry' => '3.2']],
					['circle', ['cx' => '9', 'cy' => '12.5', 'r' => '1', 'fill' => '#fff', 'stroke' => 'none']],
					['circle', ['cx' => '16', 'cy' => '21.5', 'r' => '1', 'fill' => '#fff', 'stroke' => 'none']],
				],
			],
		];

		return $icons[$eventTypeId] ?? null;
	}

	/**
	 * @param array{colour: string, label: string, shapes: array<int, array{0: string, 1: array<string, string>}>} $icon
	 */
	private function createDiaryCategoryBadge(\DOMDocument $document, array $icon): \DOMElement
	{
		$badge = $document->createElement('span');
		$badge->setAttribute('data-ra_tweaks-diary-badge', '1');
		$badge->setAttribute('title', $icon['label']);
		$badge->setAttribute('aria-label', $icon['label']);
		$badge->setAttribute('style', $this->diaryBadgeStyle($icon['colour']));
		$badge->appendChild($this->createDiaryCategorySvg($document, $icon['shapes']));

		return $badge;
	}

	/**
	 * @param array<int, array{0: string, 1: array<string, string>}> $shapes
	 */
	private function createDiaryCategorySvg(\DOMDocument $document, array $shapes): \DOMElement
	{
		$svgNamespace = 'http://www.w3.org/2000/svg';

		$svg = $document->createElementNS($svgNamespace, 'svg');
		$svg->setAttribute('width', '16');
		$svg->setAttribute('height', '16');
		$svg->setAttribute('viewBox', '0 0 24 24');
		$svg->setAttribute('fill', 'none');
		$svg->setAttribute('stroke', '#fff');
		$svg->setAttribute('stroke-width', '2');
		$svg->setAttribute('stroke-linecap', 'round');
		$svg->setAttribute('stroke-linejoin', 'round');

		foreach ($shapes as [$tag, $attributes]) {
			$shape = $document->createElementNS($svgNamespace, $tag);

			foreach ($attributes as $name => $value) {
				$shape->setAttribute($name, $value);
			}

			$svg->appendChild($shape);
		}

		return $svg;
	}

	private function diaryBadgeStyle(string $colour): string
	{
		return 'display: inline-flex; align-items: center; justify-content: center; '
			. 'width: 28px; height: 28px; margin-right: 8px; border-radius: 999px; '
			. 'background: ' . $colour . '; box-sizing: border-box; vertical-align: -7px;';
	}

	private function firstMeaningfulTextNode(\DOMNode $node): ?\DOMText
	{
		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMText && trim($child->nodeValue) !== '') {
				return $child;
			}

			if ($child instanceof \DOMElement && !in_array(strtolower($child->nodeName), ['script', 'style'], true)) {
				$match = $this->firstMeaningfulTextNode($child);

				if ($match instanceof \DOMText) {
					return $match;
				}
			}
		}

		return null;
	}

	private function applyColourToLeadingWalkText(\DOMElement $container, \DOMNode $changedNode, string $colour): void
	{
		foreach (iterator_to_array($container->childNodes) as $child) {
			$this->applyColourToNode($child, $colour);

			if ($child->isSameNode($changedNode)) {
				break;
			}
		}
	}

	private function applyColourToNode(\DOMNode $node, string $colour): void
	{
		if ($node instanceof \DOMElement) {
			if ($node->getAttribute('data-ra_tweaks-marker') === '1') {
				return;
			}

			$this->applyColour($node, $colour);

			return;
		}

		if (!$node instanceof \DOMText || trim($node->nodeValue) === '') {
			return;
		}

		$span = $node->ownerDocument->createElement('span');
		$span->setAttribute('style', 'color: ' . $colour . ';');
		$span->appendChild($node->ownerDocument->createTextNode($node->nodeValue));

		$node->parentNode?->replaceChild($span, $node);
	}

	private function stripInjectedXmlDeclaration(string $html): string
	{
		return preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $html) ?? $html;
	}

	/**
	 * Add a small browser-side pass for Ramblers programme pages that populate
	 * walk rows after Joomla has rendered the article.
	 *
	 * @param string[] $cancelTerms
	 * @param array{enabled: bool, strollerMax: float, shortMax: float, mediumMax: float, colours: array<string, string>} $walkTypeLabelling
	 */
	private function injectFrontendScript(string $body, string $marker, string $colour, array $cancelTerms, bool $alignGradeIcons, array $walkTypeLabelling): string
	{
		if (str_contains($body, 'id="plg-system-ra_tweaks-script"')) {
			return $body;
		}

		$config = json_encode(
			[
				'marker' => $marker,
				'colour' => $colour,
				'cancelTerms' => array_values($cancelTerms),
				'alignGradeIcons' => $alignGradeIcons,
				'walkTypeLabelling' => $walkTypeLabelling,
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if (!is_string($config)) {
			return $body;
		}

		$style = $alignGradeIcons ? '<style id="plg-system-ra_tweaks-style">' . $this->frontendStyle() . '</style>' : '';
		$script = $style . '<script id="plg-system-ra_tweaks-script">(' . $this->frontendScript() . ')(' . $config . ');</script>';

		if (stripos($body, '</body>') !== false) {
			return preg_replace('/<\/body>/i', $script . '</body>', $body, 1) ?? $body;
		}

		return $body . $script;
	}

	private function frontendStyle(): string
	{
		return <<<'CSS'
.walkPublished > .item,
.walkdetail > .item,
.walkPublished > .updated,
.walkPublished > .new,
.walkdetail > .updated,
.walkdetail > .new,
.walkPublished:has(> .grade),
.walkdetail:has(> .grade) {
	display: grid !important;
	grid-template-columns: max-content minmax(0, 1fr) !important;
	column-gap: 0.65em !important;
	align-items: center !important;
	width: 100% !important;
	max-width: 100% !important;
	clear: both !important;
	box-sizing: border-box !important;
	margin-bottom: 0.35em !important;
}
.walkPublished > .item > .updated,
.walkPublished > .item > .new,
.walkdetail > .item > .updated,
.walkdetail > .item > .new {
	display: contents !important;
}
.walkPublished > .item .grade,
.walkdetail > .item .grade,
.walkPublished > .updated > .grade,
.walkPublished > .new > .grade,
.walkdetail > .updated > .grade,
.walkdetail > .new > .grade,
.walkPublished > .grade,
.walkdetail > .grade {
	grid-column: 1 !important;
	grid-row: 1 !important;
	justify-self: center !important;
	align-self: center !important;
	float: none !important;
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	width: max-content !important;
	max-width: none !important;
	margin: 0 !important;
}
.walkPublished > .item .grade img,
.walkdetail > .item .grade img,
.walkPublished > .updated > .grade img,
.walkPublished > .new > .grade img,
.walkdetail > .updated > .grade img,
.walkdetail > .new > .grade img,
.walkPublished > .grade img,
.walkdetail > .grade img {
	display: block !important;
	float: none !important;
	margin: 0 auto !important;
	max-width: none !important;
}
.walkPublished > .item .pointer,
.walkdetail > .item .pointer,
.walkPublished > .updated > .pointer,
.walkPublished > .new > .pointer,
.walkdetail > .updated > .pointer,
.walkdetail > .new > .pointer,
.walkPublished > .pointer,
.walkdetail > .pointer {
	grid-column: 2 !important;
	grid-row: 1 !important;
	min-width: 0 !important;
	display: block !important;
	float: none !important;
	width: auto !important;
	max-width: 100% !important;
	white-space: normal !important;
}
CSS;
	}

	private function frontendScript(): string
	{
		return <<<'JS'
function(config) {
	"use strict";

	var marker = typeof config.marker === "string" ? config.marker : "***";
	var colour = config.colour || "#F08050";
	var cancelTerms = Array.isArray(config.cancelTerms) ? config.cancelTerms.map(function(term) {
		return String(term).toLowerCase();
	}) : ["cancelled", "canceled"];
	var alignGradeIcons = config.alignGradeIcons !== false;
	var walkTypeLabelling = config.walkTypeLabelling || { enabled: false };
	var highlightChangedWalks = marker !== "";
	var scheduled = false;

	function closestContainer(node) {
		var current = node && node.parentElement;

		while (current && current !== document.body) {
			var name = current.tagName.toLowerCase();

			if (["li", "tr", "p", "article", "section", "div"].indexOf(name) !== -1) {
				return current;
			}

			current = current.parentElement;
		}

		return node && node.parentElement ? node.parentElement : null;
	}

	function isIgnored(node) {
		var current = node && node.parentElement;

		while (current) {
			var name = current.tagName.toLowerCase();

			if (current.getAttribute("data-ra_tweaks-processed") === "1") {
				return true;
			}

			if (current.getAttribute("data-ra_tweaks-marker") === "1") {
				return true;
			}

			if (["script", "style", "textarea", "title"].indexOf(name) !== -1) {
				return true;
			}

			current = current.parentElement;
		}

		return false;
	}

	function isCancelled(container) {
		if (!container) {
			return false;
		}

		if (container.closest(".cancelledWalks")) {
			return true;
		}

		var text = (container.textContent || "").toLowerCase();

		for (var i = 0; i < cancelTerms.length; i++) {
			if (cancelTerms[i] && text.indexOf(cancelTerms[i]) !== -1) {
				return true;
			}
		}

		for (var current = container; current; current = current.parentElement) {
			var style = (current.getAttribute("style") || "").toLowerCase();
			var name = current.tagName.toLowerCase();

			if (style.indexOf("line-through") !== -1 || name === "s" || name === "strike") {
				return true;
			}
		}

		return false;
	}

	function topLevelNodeWithin(node, container) {
		var current = node;

		while (current && current.parentNode && current.parentNode !== container) {
			current = current.parentNode;
		}

		return current;
	}

	function firstTextNode(node) {
		var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, {
			acceptNode: function(textNode) {
				return textNode.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});

		return walker.nextNode();
	}

	function removeMarker(textNode) {
		textNode.nodeValue = textNode.nodeValue.split(marker).join(" ");
		textNode.nodeValue = textNode.nodeValue.replace(/\s{2,}/g, " ").replace(/^\s+/, "");
	}

	function prependMarker(container) {
		if (container.querySelector("[data-ra_tweaks-marker='1']")) {
			return;
		}

		var textNode = firstTextNode(container);
		var badge = createMarkerBadge();

		if (!textNode) {
			container.insertBefore(badge, container.firstChild);
			return;
		}

		textNode.nodeValue = textNode.nodeValue.replace(/^\s+/, "");
		textNode.parentNode.insertBefore(badge, textNode);
	}

	function createMarkerBadge() {
		var badge = document.createElement("span");
		badge.setAttribute("data-ra_tweaks-marker", "1");
		badge.setAttribute("title", "Walk details changed");
		badge.setAttribute("aria-label", "Walk details changed");
		badge.style.display = "inline-grid";
		badge.style.placeItems = "center";
		badge.style.width = "1.45em";
		badge.style.height = "1.45em";
		badge.style.marginRight = "0.3em";
		badge.style.borderRadius = "999px";
		badge.style.background = colour;
		badge.style.color = "#fff";
		badge.style.fontSize = "0.72em";
		badge.style.fontWeight = "800";
		badge.style.lineHeight = "1";
		badge.style.verticalAlign = "0.12em";
		badge.style.paddingTop = "0.12em";
		badge.style.boxSizing = "border-box";
		badge.textContent = marker;

		return badge;
	}

	function colourElement(element) {
		if (element.nodeType === Node.ELEMENT_NODE) {
			if (element.getAttribute("data-ra_tweaks-marker") === "1" || element.getAttribute("data-ra_tweaks-walk-type") === "1") {
				return;
			}

			element.style.color = colour;
			Array.prototype.slice.call(element.querySelectorAll("*")).forEach(function(child) {
				if (child.getAttribute("data-ra_tweaks-marker") === "1" || child.getAttribute("data-ra_tweaks-walk-type") === "1") {
					return;
				}

				child.style.color = colour;
			});

			return;
		}

		if (element.nodeType !== Node.TEXT_NODE || !element.nodeValue.trim()) {
			return;
		}

		var span = document.createElement("span");
		span.style.color = colour;
		span.textContent = element.nodeValue;
		element.parentNode.replaceChild(span, element);
	}

	function colourLeadingText(container, changedNode) {
		var children = Array.prototype.slice.call(container.childNodes);

		for (var i = 0; i < children.length; i++) {
			colourElement(children[i]);

			if (children[i] === changedNode) {
				break;
			}
		}
	}

	function firstElementContainingMarker(container) {
		var elements = Array.prototype.slice.call(container.querySelectorAll("*"));

		for (var i = 0; i < elements.length; i++) {
			if ((elements[i].textContent || "").indexOf(marker) !== -1) {
				return elements[i];
			}
		}

		return null;
	}

	function programmeRows() {
		return Array.prototype.slice.call(document.querySelectorAll(".walkPublished .pointer, .walkdetail .pointer"));
	}

	function programmeItems() {
		return Array.prototype.slice.call(document.querySelectorAll(".walkPublished > .item, .walkdetail > .item, .walkPublished > .updated, .walkPublished > .new, .walkdetail > .updated, .walkdetail > .new, .walkPublished, .walkdetail"));
	}

	function mediaElement(element) {
		if (!element || element.nodeType !== Node.ELEMENT_NODE) {
			return null;
		}

		if (["img", "svg", "picture"].indexOf(element.tagName.toLowerCase()) !== -1) {
			return element;
		}

		var child = element.firstElementChild;

		if (child && ["img", "svg", "picture"].indexOf(child.tagName.toLowerCase()) !== -1) {
			return child;
		}

		return null;
	}

	function firstGradeIcon(container) {
		var child = container.firstElementChild;

		if (!child) {
			return null;
		}

		if (mediaElement(child)) {
			return child;
		}

		return null;
	}

	function gradeIconCandidate(element) {
		var media = mediaElement(element);

		if (!media || media.closest("[data-ra_tweaks-grade-text], [data-ra_tweaks-grade-row]")) {
			return false;
		}

		var parent = element.parentElement;

		if (!parent || !/\d\s*(mi|km)\b/i.test(parent.textContent || "")) {
			return false;
		}

		var rect = media.getBoundingClientRect();

		if (rect.width > 0 && rect.height > 0 && (rect.width < 20 || rect.width > 90 || rect.height < 20 || rect.height > 90)) {
			return false;
		}

		return true;
	}

	function inlineGradeIconChildren(parent) {
		if (!parent) {
			return [];
		}

		return Array.prototype.slice.call(parent.children).filter(gradeIconCandidate);
	}

	function inlineGradeParentCandidate(parent) {
		if (!parent || parent.getAttribute("data-ra_tweaks-inline-grade-aligned") === "1") {
			return false;
		}

		var name = parent.tagName.toLowerCase();

		if (["p", "div", "span"].indexOf(name) === -1) {
			return false;
		}

		if (parent.closest("table, thead, tbody, tfoot, tr, td, th, ul, ol, .walkPublished, .walkdetail")) {
			return false;
		}

		if (parent.querySelector("table, ul, ol, .walkPublished, .walkdetail")) {
			return false;
		}

		if (!/\d\s*(mi|km)\b/i.test(parent.textContent || "")) {
			return false;
		}

		var icons = inlineGradeIconChildren(parent);

		if (icons.length < 2) {
			return false;
		}

		for (var i = 0; i < parent.childNodes.length; i++) {
			var node = parent.childNodes[i];

			if (node.nodeType === Node.TEXT_NODE && !node.nodeValue.trim()) {
				continue;
			}

			if (node.nodeType === Node.ELEMENT_NODE && node.tagName.toLowerCase() === "br") {
				continue;
			}

			return node.nodeType === Node.ELEMENT_NODE && gradeIconCandidate(node);
		}

		return false;
	}

	function inlineGradeParents() {
		var roots = Array.prototype.slice.call(document.querySelectorAll(".mod-custom, .custom, .module, .moduletable, main article, .com-content-article, .item-page, [itemprop='articleBody']"));
		var parents = [];

		roots.forEach(function(root) {
			Array.prototype.slice.call(root.querySelectorAll("img, svg, picture")).forEach(function(media) {
				var wrapper = media.parentElement;
				var parent = wrapper;

				if (wrapper && wrapper.textContent.trim() === "" && wrapper.parentElement) {
					parent = wrapper.parentElement;
				}

				if (parent && parents.indexOf(parent) === -1 && inlineGradeParentCandidate(parent)) {
					parents.push(parent);
				}
			});
		});

		return parents;
	}

	function setImportant(element, property, value) {
		element.style.setProperty(property, value, "important");
	}

	function applyGradeRowLayout(row, icon, textWrap) {
		var media = mediaElement(icon);

		setImportant(row, "display", "grid");
		setImportant(row, "grid-template-columns", "max-content minmax(0, 1fr)");
		setImportant(row, "column-gap", "0.65em");
		setImportant(row, "align-items", "center");
		setImportant(row, "grid-auto-rows", "auto");
		setImportant(row, "margin-bottom", "0.35em");
		setImportant(row, "box-sizing", "border-box");
		setImportant(row, "width", "100%");
		setImportant(row, "max-width", "100%");
		setImportant(row, "clear", "both");
		setImportant(icon, "grid-column", "1");
		setImportant(icon, "grid-row", "1");
		setImportant(icon, "justify-self", "center");
		setImportant(icon, "align-self", "center");
		setImportant(icon, "float", "none");
		setImportant(icon, "display", "inline-flex");
		setImportant(icon, "align-items", "center");
		setImportant(icon, "justify-content", "center");
		setImportant(icon, "width", "max-content");
		setImportant(icon, "max-width", "none");
		setImportant(icon, "margin", "0");
		setImportant(textWrap, "grid-column", "2");
		setImportant(textWrap, "grid-row", "1");
		setImportant(textWrap, "min-width", "0");
		setImportant(textWrap, "display", "block");
		setImportant(textWrap, "float", "none");
		setImportant(textWrap, "width", "auto");
		setImportant(textWrap, "max-width", "100%");
		setImportant(textWrap, "white-space", "normal");

		if (media) {
			setImportant(media, "display", "block");
			setImportant(media, "float", "none");
			setImportant(media, "margin", "0 auto");
			setImportant(media, "max-width", "none");
		}
	}

	function alignGradeIcon(container) {
		if (!alignGradeIcons || !container || container.getAttribute("data-ra_tweaks-grade-aligned") === "1") {
			return;
		}

		var icon = firstGradeIcon(container);

		if (!icon) {
			return;
		}

		var textWrap = document.createElement("span");
		var moved = false;

		textWrap.setAttribute("data-ra_tweaks-grade-text", "1");

		while (icon.nextSibling) {
			textWrap.appendChild(icon.nextSibling);
			moved = true;
		}

		if (!moved) {
			return;
		}

		container.appendChild(textWrap);
		applyGradeRowLayout(container, icon, textWrap);
		container.setAttribute("data-ra_tweaks-grade-aligned", "1");
	}

	function programmeItemPointer(item) {
		var wrapper = item;

		if (item.classList.contains("walkPublished") || item.classList.contains("walkdetail")) {
			wrapper = item.querySelector(":scope > .item, :scope > .updated, :scope > .new") || item;
		}

		var pointer = wrapper.querySelector(".pointer");
		var grade = wrapper.querySelector(".grade");

		if (!pointer || !grade || !/\d\s*(mi|km)\b/i.test(pointer.textContent || "")) {
			return null;
		}

		return { wrapper: wrapper, pointer: pointer, grade: grade };
	}

	function alignProgrammeItem(item) {
		if (!alignGradeIcons || !item || item.getAttribute("data-ra_tweaks-grade-aligned") === "1") {
			return;
		}

		var found = programmeItemPointer(item);

		if (!found) {
			return;
		}

		applyGradeRowLayout(found.wrapper, found.grade, found.pointer);
		found.wrapper.setAttribute("data-ra_tweaks-grade-aligned", "1");
		item.setAttribute("data-ra_tweaks-grade-aligned", "1");
	}

	function extractMiles(text) {
		var match = /(\d+(?:\.\d+)?)\s*mi\b/i.exec(text || "");

		return match ? parseFloat(match[1]) : null;
	}

	function walkTypeLabel(miles) {
		if (miles === null || isNaN(miles)) {
			return null;
		}

		if (miles <= walkTypeLabelling.strollerMax) {
			return "STROLLER";
		}

		if (miles <= walkTypeLabelling.shortMax) {
			return "SHORT WALK";
		}

		if (miles <= walkTypeLabelling.mediumMax) {
			return "MEDIUM WALK";
		}

		return "LONG WALK";
	}

	function walkTypeColour(label) {
		var colours = walkTypeLabelling.colours || {};

		switch (label) {
			case "STROLLER":
				return colours.stroller;
			case "SHORT WALK":
				return colours.short;
			case "MEDIUM WALK":
				return colours.medium;
			case "LONG WALK":
				return colours.long;
			case "EVENING WALK":
				return colours.evening;
			default:
				return null;
		}
	}

	function appendWalkTypeBadge(pointer, label, colour) {
		var badge = document.createElement("span");
		badge.setAttribute("data-ra_tweaks-walk-type", "1");
		badge.style.color = colour;
		badge.style.fontWeight = "700";
		badge.textContent = " " + label;

		pointer.appendChild(badge);
	}

	function eveningWalkTextNode(pointer) {
		var walker = document.createTreeWalker(pointer, NodeFilter.SHOW_TEXT, {
			acceptNode: function(textNode) {
				return /evening\s+walk/i.test(textNode.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});

		return walker.nextNode();
	}

	function stripEveningWalk(textNode) {
		textNode.nodeValue = textNode.nodeValue
			.replace(/[\s,–—-]*evening\s+walk[\s,]*/i, " ")
			.replace(/\s{2,}/g, " ")
			.trim();
	}

	function labelProgrammeItem(item) {
		if (!walkTypeLabelling.enabled || !item || item.getAttribute("data-ra_tweaks-walk-type-labelled") === "1") {
			return;
		}

		if (isCancelled(item)) {
			return;
		}

		var found = programmeItemPointer(item);

		if (!found) {
			return;
		}

		item.setAttribute("data-ra_tweaks-walk-type-labelled", "1");

		var eveningNode = eveningWalkTextNode(found.pointer);

		if (eveningNode) {
			stripEveningWalk(eveningNode);
			appendWalkTypeBadge(found.pointer, "EVENING WALK", walkTypeColour("EVENING WALK"));

			return;
		}

		if (/\b(LONG|SHORT|MEDIUM)\s+WALK\b|\bSTROLLER\b/i.test(found.pointer.textContent || "")) {
			return;
		}

		var label = walkTypeLabel(extractMiles(found.pointer.textContent));
		var colour = label ? walkTypeColour(label) : null;

		if (!label || !colour) {
			return;
		}

		appendWalkTypeBadge(found.pointer, label, colour);
	}

	function trimTrailingBreak(textWrap) {
		while (textWrap.lastChild && textWrap.lastChild.nodeType === Node.ELEMENT_NODE && textWrap.lastChild.tagName.toLowerCase() === "br") {
			textWrap.removeChild(textWrap.lastChild);
		}
	}

	function alignInlineGradeParent(parent) {
		if (!alignGradeIcons || !inlineGradeParentCandidate(parent)) {
			return;
		}

		var nodes = Array.prototype.slice.call(parent.childNodes);
		var fragment = document.createDocumentFragment();
		var currentText = null;
		var changed = false;

		nodes.forEach(function(node) {
			if (node.nodeType === Node.ELEMENT_NODE && gradeIconCandidate(node)) {
				if (currentText) {
					trimTrailingBreak(currentText);
				}

				var row = document.createElement("span");
				var textWrap = document.createElement("span");

				row.setAttribute("data-ra_tweaks-grade-row", "1");
				textWrap.setAttribute("data-ra_tweaks-grade-text", "1");
				row.appendChild(node);
				row.appendChild(textWrap);
				applyGradeRowLayout(row, node, textWrap);
				fragment.appendChild(row);
				currentText = textWrap;
				changed = true;

				return;
			}

			if (currentText) {
				currentText.appendChild(node);
			} else {
				fragment.appendChild(node);
			}
		});

		if (!changed) {
			return;
		}

		if (currentText) {
			trimTrailingBreak(currentText);
		}

		parent.appendChild(fragment);
		parent.setAttribute("data-ra_tweaks-inline-grade-aligned", "1");
	}

	function processElement(container) {
		if (!highlightChangedWalks || !container || isCancelled(container) || container.getAttribute("data-ra_tweaks-processed") === "1") {
			return;
		}

		if ((container.textContent || "").indexOf(marker) === -1) {
			return;
		}

		var markerElement = firstElementContainingMarker(container);

		if (!markerElement) {
			return;
		}

		var textNodes = Array.prototype.slice.call(markerElement.childNodes).filter(function(child) {
			return child.nodeType === Node.TEXT_NODE && child.nodeValue.indexOf(marker) !== -1;
		});

		if (textNodes.length) {
			removeMarker(textNodes[0]);
		}

		prependMarker(container);
		colourElement(container);
		container.setAttribute("data-ra_tweaks-processed", "1");
	}

	function process() {
		scheduled = false;
		programmeItems().forEach(alignProgrammeItem);
		programmeItems().forEach(labelProgrammeItem);
		programmeRows().forEach(function(container) {
			alignGradeIcon(container);
			processElement(container);
		});
		inlineGradeParents().forEach(alignInlineGradeParent);

		if (!highlightChangedWalks || typeof document.createTreeWalker !== "function") {
			return;
		}

		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
			acceptNode: function(node) {
				if (!node.nodeValue || node.nodeValue.indexOf(marker) === -1 || isIgnored(node)) {
					return NodeFilter.FILTER_REJECT;
				}

				return NodeFilter.FILTER_ACCEPT;
			}
		});
		var nodes = [];
		var node;

		while ((node = walker.nextNode())) {
			nodes.push(node);
		}

		nodes.forEach(function(textNode) {
			var container = closestContainer(textNode);

			if (!container || isCancelled(container)) {
				return;
			}

			var changedNode = topLevelNodeWithin(textNode, container);

			removeMarker(textNode);
			prependMarker(container);
			colourLeadingText(container, changedNode);
		});
	}

	function schedule() {
		if (scheduled) {
			return;
		}

		scheduled = true;
		window.setTimeout(process, 50);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", schedule);
	} else {
		schedule();
	}

	new MutationObserver(schedule).observe(document.documentElement, {
		childList: true,
		subtree: true
	});
}
JS;
	}
}
