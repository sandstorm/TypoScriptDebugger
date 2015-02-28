<?php
namespace Sandstorm\TypoScriptDebugger;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Sandstorm.TypoScriptDebugger".*
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Eel\Context;
use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Controller\ControllerContext;
use TYPO3\Flow\Reflection\ObjectAccess;
use TYPO3\TypoScript\Core\DebuggerInterface;
use TYPO3\TypoScript\TypoScriptObjects\AbstractTypoScriptObject;
use TYPO3\TypoScript\TypoScriptObjects\CaseImplementation;

/**
 * The TypoScript Debugger is being called by the TypoScript Runtime during TS evaluation.
 *
 * It generally works in two phases: First, it collects data from within the TypoScript Runtime,
 * and second it uses this data for various renderings.
 *
 * The collected evaluation data inside Debugger::$evaluationTrace forms a tree.
 *
 * The debugger works under the assumption that the TypoScript rendering is
 * *deterministic*; only depending on URL arguments and some (non-changing) state.
 * We expect that if a URL is re-loaded, it triggers the same rendering and the
 * same internal flow through the TypoScript runtime.
 */
class Debugger implements DebuggerInterface {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Resource\ResourceManager
	 */
	protected $resourceManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Eel\CompilingEvaluator
	 */
	protected $eelEvaluator;

	protected $currentlyRenderingMarkers = TRUE;

	/**
	 * Main data structure which is built up during the data collection phase.
	 * Forms a tree of single "evaluation" elements. An evaluation element
	 * contains the following parts:
	 *
	 * - Directly Recorded Properties
	 *   - fullPath (string): the full TypoScript path as it is known to the Runtime
	 *   - tsObject (object): reference to the AbstractTsObject being rendered here
	 *   - configuration (ass. array): Configuration array for this TypoScript object
	 *   - output (mixed): rendered HTML of this TypoScript object
	 *   - context (ass. array): the currently active TypoScript context
	 *   - token (integer): Token for this rendering
	 *   - children (array): ordered array of sub-$evaluation-elements which are
	 *                       rendered inside this TypoScript object.
	 *   - _oldMarkerValue (boolean); OPTIONAL.
	 *
	 * - Computed Properties
	 *   - condensedPath (string): absolute TypoScript path which does not contain TS type information
	 *   - relativePath (string): relative TypoScript path which does not contain TS type information
	 *   - arrayPath (string): path to this $evaluation in the $evaluationTrace datastructure; ready for traversal in JavaScript
	 *   - implementationClassName (string): implementation class name of this TS object
	 *   - objectType (string): TypoScript object type, extracted from configuration.
	 *   - contextAsString (ass. array): the context which is converted to a displayable string
	 *   - metaConfiguration (ass. array): "meta" configuration as exctracted of configuration array
	 *
	 * @var array
	 */
	protected $evaluationTrace = array();

	/**
	 * Currently running evaluations.
	 *
	 * References to elements in $evaluationTrace where beginEvaluationCycle
	 * has been called, but endEvaluationCycle has not been called yet.
	 *
	 * @var array
	 */
	protected $evaluationStack = NULL;

	/**
	 * We wrap a rendered TypoScript object into BEGIN_[TOKEN] and END_[TOKEN]
	 * comments such that we're able to deterministically find the rendered
	 * DOM parts created by some TypoScript object.
	 *
	 * This counter contains the next non-used token number.
	 *
	 * @var integer
	 */
	protected $tokenCounter = 0;

	/***********************************
	 * SECTION: DATA COLLECTION
	 *
	 * The following methods are called from within the TypoScript runtime's
	 * evaluate-method, where they can collect various information pieces
	 * and store them inside $this->evaluationTrace.
	 *
	 * The methods are listed in the order of calling.
	 ***********************************/

	/**
	 * Begin of an evaluation cycle.
	 *
	 * Initializes the evaluation trace at the current position and adds it
	 * to the evaluation stack.
	 *
	 * @param string $typoScriptPath
	 * @return void
	 */
	public function beginEvaluationCycle($typoScriptPath) {
		$emptyEvaluation = array(
			'fullPath' => $typoScriptPath,
			'configuration' => NULL,
			'output' => NULL,
			'context' => array(),
			'token' => NULL,
			'tsObject' => NULL
		);
		if ($this->evaluationStack === NULL) {
				// First-time initialization.
			$this->evaluationStack = array(&$this->evaluationTrace);
			$this->evaluationTrace = $emptyEvaluation;
		}

		$evaluation = $emptyEvaluation;

		$this->evaluationStack[count($this->evaluationStack) - 1]['children'][] = &$evaluation;
		$this->evaluationStack[] = &$evaluation;
	}

	/**
	 * Store the currently used TypoScript configuration of the currently rendered
	 * TypoScript object.
	 *
	 * @param array $configuration
	 * @return void
	 */
	public function setCurrentConfiguration(array $configuration) {
		$this->evaluationStack[count($this->evaluationStack) - 1]['configuration'] = $configuration;

		if (isset($configuration['__objectType']) && in_array($configuration['__objectType'], array('TYPO3.TypoScript:Attributes', 'TYPO3.TypoScript:Tag', 'TYPO3.Neos:ContentElementWrapping'))) {
			$this->evaluationStack[count($this->evaluationStack) - 1]['_oldMarkerValue'] = $this->currentlyRenderingMarkers;
			$this->currentlyRenderingMarkers = FALSE;
		}
	}

	/**
	 * Store the final context array and the TypoScript object reference.
	 *
	 * Is called directly before evaluate() is called on the TypoScript object;
	 * so if this method is called exceptions have not occured during initialization.
	 *
	 * @param array $context
	 * @param AbstractTypoScriptObject $tsObject
	 * @return void
	 */
	public function beforeTypoScriptEvaluation(array $context, AbstractTypoScriptObject $tsObject) {
		$this->evaluationStack[count($this->evaluationStack) - 1]['context'] = $context;
		$this->evaluationStack[count($this->evaluationStack) - 1]['tsObject'] = $tsObject;
	}

	/**
	 * End of an evaluation cycle.
	 *
	 * Stores and post-processes the final output, inserting tokens.
	 *
	 * @param string $output
	 * @param bool $renderOutput
	 * @return void
	 */
	public function endEvaluationCycle(&$output, $renderOutput = TRUE) {
		if ($renderOutput && is_string($output) && $output !== CaseImplementation::MATCH_NORESULT) {
			$this->evaluationStack[count($this->evaluationStack) - 1]['output'] = preg_replace('/<!--(BEGIN|END)_[A-Z0-9_]*-->/', '', preg_replace('/\s+/', ' ', $output));

			if ($this->currentlyRenderingMarkers === TRUE) {
				$this->evaluationStack[count($this->evaluationStack) - 1]['token'] = $this->tokenCounter++;
				$output = '<!--BEGIN_' . $this->evaluationStack[count($this->evaluationStack) - 1]['token'] . '-->'
					. $output
					. '<!--END_' . $this->evaluationStack[count($this->evaluationStack) - 1]['token'] . '-->';
			}
		}


		if (isset($this->evaluationStack[count($this->evaluationStack) - 1]['_oldMarkerValue'])) {
			$this->currentlyRenderingMarkers = $this->evaluationStack[count($this->evaluationStack) - 1]['_oldMarkerValue'];
		}

		array_pop($this->evaluationStack);
	}

	/***********************************
	 * SECTION: OUTPUT
	 *
	 * The postProcessOutput method is called after all evaluation data has been
	 * collected; and acts as a "dispatcher", which, depending on the request parameters,
	 * will display different output.
	 *
	 * - if no request parameter is given, only the Debugging Snippet is inserted
	 *   into the final rendered page.
	 * - if the request parameter __typo3-typoscript-debugger is given, the
	 *   page-output is completely replaced and the debugger is rendered instead.
	 * - if the request parameter __typo3-typoscript-debugger-eelExpression
	 *   is given, the given eel expression is evaluated and the result returned
	 *   (AJAX request from inside the TypoScript debugger)
	 ***********************************/

	/**
	 * This method is called at the final end of Runtime::render() and is the
	 * main entry-point for rendering the debugger or augumenting the page.
	 *
	 * @param string $output
	 * @param ControllerContext $controllerContext
	 * @return string The post-processed or replaced output
	 */
	public function postProcessOutput($output, ControllerContext $controllerContext) {
		if (count($this->evaluationStack) === 1) {
			$this->addComputedPropertiesToEvaluation($this->evaluationTrace);

			if ($controllerContext->getRequest()->getInternalArgument('__typo3-typoscript-debugger-eelExpression')) {
				$output = $this->renderEelExpression($controllerContext);
			} else {
				$output .= $this->renderDebuggingSnippet();
			}
		}

		return $output;
	}

	/**
	 * Renders the small HTML snippet which displays the debug button on the rendered
	 * webpage.
	 *
	 * @return string
	 */
	protected function renderDebuggingSnippet() {
		$debuggingSnippet = file_get_contents('resource://Sandstorm.TypoScriptDebugger/Private/DebuggingSnippet.html');
		$evaluationTrace = $this->cleanEvaluationForDebugger($this->evaluationTrace);

		return str_replace(array('###BASE_URL###', '###EVALUATION_TRACE###'), array($this->resourceManager->getPublicPackageResourceUri('Sandstorm.TypoScriptDebugger', ''), json_encode(json_encode($evaluationTrace))), $debuggingSnippet);
	}

	/**
	 * Clean up the evaluation array for usage as JSON inside debugger (in order to
	 * reduce transferred data to client)
	 *
	 * @param array $evaluation
	 * @return array the cleaned evaluation
	 */
	protected function cleanEvaluationForDebugger(array $evaluation) {
		unset($evaluation['tsObject']);
		unset($evaluation['metaConfiguration']['class']);
		unset($evaluation['configuration']['__meta']);
		unset($evaluation['configuration']['__objectType']);

		if (isset($evaluation['children'])) {
			foreach ($evaluation['children'] as $key => $childEvaluation) {
				$evaluation['children'][$key] = $this->cleanEvaluationForDebugger($childEvaluation);
			}
		}

		return $evaluation;
	}

	/**
	 * Render an eel expression which should be evaluated from inside the TypoScript debugger,
	 * and return the result in a format understood by the debugger.
	 *
	 * Uses the following request arguments:
	 *
	 * - __typo3-typoscript-debugger-eelExpression: The eel expression which should be evaluated
	 * - __typo3-typoscript-debugger-currentArrayPath: The array path inside $this->evaluationTrace
	 *
	 * @param ControllerContext $controllerContext
	 * @return string
	 */
	protected function renderEelExpression(ControllerContext $controllerContext) {
		$eelExpression = $controllerContext->getRequest()->getInternalArgument('__typo3-typoscript-debugger-eelExpression');
		$currentArrayPath = $controllerContext->getRequest()->getInternalArgument('__typo3-typoscript-debugger-currentArrayPath');
		$currentArrayPath = str_replace('[', '.', $currentArrayPath);
		$currentArrayPath = str_replace(']', '', $currentArrayPath);
		$currentArrayPath = trim($currentArrayPath, '.');

		$evaluation = ObjectAccess::getPropertyPath($this->evaluationTrace, $currentArrayPath);

		$eelContextVariables = $evaluation['context'];
		$eelContextVariables['q'] = function($element) {
			return new FlowQuery(array($element));
		};
		$eelContextVariables['this'] = $evaluation['tsObject'];
		$context = new Context($eelContextVariables);
		return json_encode($this->eelEvaluator->evaluate($eelExpression, $context));
	}

	/**
	 * Add computed properties to the evaluation
	 *
	 * @param array $evaluation
	 * @param string $parentPath
	 * @param string $arrayPath
	 */
	protected function addComputedPropertiesToEvaluation(array &$evaluation, $parentPath = '', $arrayPath = '') {
		$evaluation['implementationClassName'] = isset($evaluation['configuration']['__meta']['class']) ? $evaluation['configuration']['__meta']['class'] : '';
		$evaluation['metaConfiguration'] = (isset($evaluation['configuration']['__meta']) ? $evaluation['configuration']['__meta'] : array());
		$evaluation['objectType'] = (isset($evaluation['configuration']['__objectType']) ? $evaluation['configuration']['__objectType'] : '');

		$contextAsString = array();
		foreach ($evaluation['context'] as $key => $value) {
			if (is_object($value) && !method_exists($value, '__toString')) {
				$contextAsString[$key] = '(object)' . get_class($value);
			} elseif (is_array($value)) {
				$contextAsString[$key] = '(array)';
			} else {
				$contextAsString[$key] = (string) $value;
			}
		}
		$evaluation['contextAsString'] = $contextAsString;

		$evaluation['arrayPath'] = $arrayPath;
		$evaluation['condensedPath'] = preg_replace('/<[^>]+>/', '', $evaluation['fullPath']);
		if (strlen($parentPath) > 0 && strpos($evaluation['condensedPath'], $parentPath . '/') === 0) {
			$evaluation['relativePath'] = substr($evaluation['condensedPath'], strlen($parentPath . '/'));
		} else {
			$evaluation['relativePath'] = '/' . $evaluation['condensedPath'];
		}

		if (isset($evaluation['children'])) {
			foreach ($evaluation['children'] as $key => &$child) {
				$this->addComputedPropertiesToEvaluation($child, $evaluation['condensedPath'], $arrayPath . '.children[' . $key . ']');
			}
		}
	}
}
?>