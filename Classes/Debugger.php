<?php
namespace Sandstorm\FusionDebugger;

/*
 * This file is part of the Sandstorm.FusionDebugger package.
 *
 * (c) Sebastian Kurfürst
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Eel\Context;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Fusion\Core\DebuggerInterface;
use Neos\Fusion\FusionObjects\AbstractFusionObject;
use Neos\Fusion\FusionObjects\CaseImplementation;
use Neos\Utility\ObjectAccess;

/**
 * The Fusion Debugger is being called by the Fusion Runtime during evaluation.
 *
 * It generally works in two phases: First, it collects data from within the Fusion Runtime,
 * and second it uses this data for various renderings.
 *
 * The collected evaluation data inside Debugger::$evaluationTrace forms a tree.
 *
 * The debugger works under the assumption that the Fusion rendering is
 * *deterministic*; only depending on URL arguments and some (non-changing) state.
 * We expect that if a URL is re-loaded, it triggers the same rendering and the
 * same internal flow through the Fusion runtime.
 */
class Debugger implements DebuggerInterface
{
    /**
     * @Flow\Inject
     * @var \Neos\Flow\ResourceManagement\ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var \Neos\Eel\CompilingEvaluator
     */
    protected $eelEvaluator;

    protected $currentlyRenderingMarkers = true;

    /**
     * Main data structure which is built up during the data collection phase.
     * Forms a tree of single "evaluation" elements. An evaluation element
     * contains the following parts:
     *
     * - Directly Recorded Properties
     *   - fullPath (string): the full Fusion path as it is known to the Runtime
     *   - fusionObject (object): reference to the AbstractFusionObject being rendered here
     *   - configuration (ass. array): Configuration array for this Fusion object
     *   - output (mixed): rendered HTML of this Fusion object
     *   - context (ass. array): the currently active Fusion context
     *   - token (integer): Token for this rendering
     *   - children (array): ordered array of sub-$evaluation-elements which are
     *                       rendered inside this Fusion object.
     *   - _oldMarkerValue (boolean); OPTIONAL.
     *
     * - Computed Properties
     *   - condensedPath (string): absolute Fusion path which does not contain Fusion type information
     *   - relativePath (string): relative Fusion path which does not contain Fusion type information
     *   - arrayPath (string): path to this $evaluation in the $evaluationTrace datastructure; ready for traversal in JavaScript
     *   - implementationClassName (string): implementation class name of this Fusion object
     *   - objectType (string): Fusion object type, extracted from configuration.
     *   - contextAsString (ass. array): the context which is converted to a displayable string
     *   - metaConfiguration (ass. array): "meta" configuration as exctracted of configuration array
     *
     * @var array
     */
    protected $evaluationTrace = [];

    /**
     * Currently running evaluations.
     *
     * References to elements in $evaluationTrace where beginEvaluationCycle
     * has been called, but endEvaluationCycle has not been called yet.
     *
     * @var array
     */
    protected $evaluationStack = null;

    /**
     * We wrap a rendered Fusion object into BEGIN_[TOKEN] and END_[TOKEN]
     * comments such that we're able to deterministically find the rendered
     * DOM parts created by some Fusion object.
     *
     * This counter contains the next non-used token number.
     *
     * @var integer
     */
    protected $tokenCounter = 0;

    /***********************************
     * SECTION: DATA COLLECTION
     *
     * The following methods are called from within the Fusion runtime's
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
     * @param string $fusionPath
     * @return void
     */
    public function beginEvaluationCycle($fusionPath)
    {
        $emptyEvaluation = [
            'fullPath' => $fusionPath,
            'configuration' => null,
            'output' => null,
            'context' => [],
            'token' => null,
            'fusionObject' => null
        ];
        if ($this->evaluationStack === null) {
            // First-time initialization.
            $this->evaluationStack = [&$this->evaluationTrace];
            $this->evaluationTrace = $emptyEvaluation;
        }

        $evaluation = $emptyEvaluation;

        $this->evaluationStack[count($this->evaluationStack) - 1]['children'][] = &$evaluation;
        $this->evaluationStack[] = &$evaluation;
    }

    /**
     * Store the currently used Fusion configuration of the currently rendered
     * Fusion object.
     *
     * @param array $configuration
     * @return void
     */
    public function setCurrentConfiguration(array $configuration)
    {
        $this->evaluationStack[count($this->evaluationStack) - 1]['configuration'] = $configuration;

        if (isset($configuration['__objectType']) && in_array($configuration['__objectType'], ['Neos.Fusion:Attributes', 'Neos.Fusion:Tag', 'Neos.Neos:ContentElementWrapping'])) {
            $this->evaluationStack[count($this->evaluationStack) - 1]['_oldMarkerValue'] = $this->currentlyRenderingMarkers;
            $this->currentlyRenderingMarkers = false;
        }
    }

    /**
     * Store the final context array and the Fusion object reference.
     *
     * Is called directly before evaluate() is called on the Fusion object;
     * so if this method is called exceptions have not occured during initialization.
     *
     * @param array $context
     * @param AbstractFusionObject $fusionObject
     * @return void
     */
    public function beforeFusionEvaluation(array $context, AbstractFusionObject $fusionObject)
    {
        $this->evaluationStack[count($this->evaluationStack) - 1]['context'] = $context;
        $this->evaluationStack[count($this->evaluationStack) - 1]['fusionObject'] = $fusionObject;
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
    public function endEvaluationCycle(&$output, $renderOutput = true)
    {
        if ($renderOutput && is_string($output) && $output !== CaseImplementation::MATCH_NORESULT) {
            $this->evaluationStack[count($this->evaluationStack) - 1]['output'] = preg_replace('/<!--(BEGIN|END)_[A-Z0-9_]*-->/', '', preg_replace('/\s+/', ' ', $output));

            if ($this->currentlyRenderingMarkers === true) {
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
     * - if the request parameter __neos-fusion-debugger is given, the
     *   page-output is completely replaced and the debugger is rendered instead.
     * - if the request parameter __neos-fusion-debugger-eelExpression
     *   is given, the given eel expression is evaluated and the result returned
     *   (AJAX request from inside the Fusion debugger)
     ***********************************/

    /**
     * This method is called at the final end of Runtime::render() and is the
     * main entry-point for rendering the debugger or augumenting the page.
     *
     * @param string $output
     * @param ControllerContext $controllerContext
     * @return string The post-processed or replaced output
     */
    public function postProcessOutput($output, ControllerContext $controllerContext)
    {
        if (count($this->evaluationStack) === 1) {
            $this->addComputedPropertiesToEvaluation($this->evaluationTrace);

            if ($controllerContext->getRequest()->getInternalArgument('__neos-fusion-debugger-eelExpression')) {
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
    protected function renderDebuggingSnippet()
    {
        $debuggingSnippet = file_get_contents('resource://Sandstorm.FusionDebugger/Private/DebuggingSnippet.html');
        $evaluationTrace = $this->cleanEvaluationForDebugger($this->evaluationTrace);

        return str_replace(['###BASE_URL###', '###EVALUATION_TRACE###'], [$this->resourceManager->getPublicPackageResourceUri('Sandstorm.FusionDebugger', ''), json_encode(json_encode($evaluationTrace))], $debuggingSnippet);
    }

    /**
     * Clean up the evaluation array for usage as JSON inside debugger (in order to
     * reduce transferred data to client)
     *
     * @param array $evaluation
     * @return array the cleaned evaluation
     */
    protected function cleanEvaluationForDebugger(array $evaluation)
    {
        unset($evaluation['fusionObject']);
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
     * Render an eel expression which should be evaluated from inside the Fusion debugger,
     * and return the result in a format understood by the debugger.
     *
     * Uses the following request arguments:
     *
     * - __neos-fusion-debugger-eelExpression: The eel expression which should be evaluated
     * - __neos-fusion-debugger-currentArrayPath: The array path inside $this->evaluationTrace
     *
     * @param ControllerContext $controllerContext
     * @return string
     */
    protected function renderEelExpression(ControllerContext $controllerContext)
    {
        $eelExpression = $controllerContext->getRequest()->getInternalArgument('__neos-fusion-debugger-eelExpression');
        $currentArrayPath = $controllerContext->getRequest()->getInternalArgument('__neos-fusion-debugger-currentArrayPath');
        $currentArrayPath = str_replace('[', '.', $currentArrayPath);
        $currentArrayPath = str_replace(']', '', $currentArrayPath);
        $currentArrayPath = trim($currentArrayPath, '.');

        $evaluation = ObjectAccess::getPropertyPath($this->evaluationTrace, $currentArrayPath);

        $eelContextVariables = $evaluation['context'];
        $eelContextVariables['q'] = function ($element) {
            return new FlowQuery([$element]);
        };
        $eelContextVariables['this'] = $evaluation['fusionObject'];
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
    protected function addComputedPropertiesToEvaluation(array &$evaluation, $parentPath = '', $arrayPath = '')
    {
        $evaluation['implementationClassName'] = isset($evaluation['configuration']['__meta']['class']) ? $evaluation['configuration']['__meta']['class'] : '';
        $evaluation['metaConfiguration'] = (isset($evaluation['configuration']['__meta']) ? $evaluation['configuration']['__meta'] : []);
        $evaluation['objectType'] = (isset($evaluation['configuration']['__objectType']) ? $evaluation['configuration']['__objectType'] : '');

        $contextAsString = [];
        foreach ($evaluation['context'] as $key => $value) {
            if (is_object($value) && !method_exists($value, '__toString')) {
                $contextAsString[$key] = '(object)' . get_class($value);
            } elseif (is_array($value)) {
                $contextAsString[$key] = '(array)';
            } else {
                $contextAsString[$key] = (string)$value;
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
