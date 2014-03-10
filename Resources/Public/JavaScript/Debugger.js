(function(Channel, $, Handlebars, style_html) {

	////////////////////////////////////////////////
	// HELPERS
	////////////////////////////////////////////////
	Handlebars.registerHelper('eachProperty', function(context, options) {
		var ret = '', prop;
		for (prop in context) {
			ret = ret + options.fn({ key: prop, value: context[prop] });
		}
		return ret;
	});

	function highlightAllArrayPathsWithSameValue(key, value) {
		var k, v, iterator, evaluation, stringifiedValue = JSON.stringify(value);

		$('.highlighted').removeClass('highlighted');

		iterator = function(currentPath) {
			evaluation = eval('window.evaluationTrace' + currentPath);
			if (evaluation[key] && JSON.stringify(evaluation[key]) === stringifiedValue) {
				$('.typoScriptEvaluationTree [data-arraypath="' + currentPath + '"]').addClass('highlighted');
			}
			if (evaluation.children) {
				for (k in evaluation.children) {
					iterator(currentPath + '.children[' + k + ']');
				}
			}
		}
		iterator('');
	}

	$(document).ready(function() {
		var channel;
		////////////////////////////////////////////////
		// COMMUNICATION CHANNEL
		////////////////////////////////////////////////
		if (window.opener) {
			channel = Channel.build({
				window: window.opener,
				origin: window.location.protocol + '//' + window.location.host,
				scope: 'typo3-typoscript-debugger',
				onReady: function() {
					$('.status').addClass('label-success');
				}
			});

			channel.bind('highlightElement', function(trans, token) {
				highlightTreeNode($('.typoScriptEvaluationTree [data-token="' + token + '"]'), false);
			});
			channel.bind('selectElement', function(trans, token) {
				highlightTreeNode($('.typoScriptEvaluationTree [data-token="' + token + '"]'), false, true);
			});

			var treeTemplate = Handlebars.compile($('#tree-template').html());
			Handlebars.registerPartial('tree', $('#tree-template').html());

			channel.bind('updateEvaluationTrace', function(trans, evaluationTraceAsString) {
				window.evaluationTrace = JSON.parse(evaluationTraceAsString);

				$('.typoScriptEvaluationTree').html(treeTemplate(window.evaluationTrace));
			});
		}


		////////////////////////////////////////////////
		// INTERACTIVITY
		////////////////////////////////////////////////
		var arrayPath, evaluation, detailsTemplate, stylingOptions = {indent_size: 2};

		detailsTemplate = Handlebars.compile($('#details-template').html());

		$('.typoScriptEvaluationTree').on('mouseenter', 'li > span', function(e) {
			highlightTreeNode($(this), true, false);
		});
		$('.typoScriptEvaluationTree').on('mouseleave', 'li > span', function(e) {
			$('.highlighted').removeClass('highlighted');
				// Update right area
			if ($('.selected').length > 0) {
				highlightTreeNode($('.selected'), false, true);
			}
			if (channel) channel.call({
				method: 'unhighlightElements',
				success: function() {
				}
			});
		});

		$('.typoScriptEvaluationTree').on('click', 'li > span', function(e) {
			e.stopPropagation();
			highlightTreeNode($(this), true, true);
		});

		function highlightTreeNode($node, sendToPage, select) {
			var className = (select ? 'selected' : 'highlighted');
			$('.' + className).removeClass(className);
			$node.addClass(className);
			arrayPath = $node.attr('data-arraypath');
			evaluation = eval('window.evaluationTrace' + arrayPath);

			if (!evaluation.processed) {
					// we format the HTML twice here, as this seems to make a difference under some circumstances
				evaluation.output = style_html(style_html(evaluation.output, stylingOptions), stylingOptions);
				evaluation.processed = true;
			}

			$('.details').html(detailsTemplate(evaluation));
			$('.details').attr('data-arraypath', arrayPath);

			if (sendToPage) {
				if (channel) channel.call({
					method: (select ? 'selectElement': 'highlightElement'),
					params: evaluation.token,
					success: function() {
					}
				});
			}
		}

		$('.details').on('mouseenter', '.context', function() {
			arrayPath = $('.details').attr('data-arraypath');
			evaluation = eval('window.evaluationTrace' + arrayPath);
			highlightAllArrayPathsWithSameValue('contextAsString', evaluation.contextAsString);
		});
		$('.details').on('mouseleave', '.context', function() {
			$('.highlighted').removeClass('highlighted');
		});


		$('.inspect-page').click(function() {
			if ($(this).hasClass('active')) {
				if (channel) channel.call({
					method: 'deactivateInspectMode',
					success: function() {
					}
				});
			} else {
				if (channel) channel.call({
					method: 'activateInspectMode',
					success: function() {
					}
				});
				window.opener.focus();
			}
			$(this).toggleClass('active');
		});

		////////////////////////////////////////////////
		// EEL EXPRESSION EVALUATOR
		////////////////////////////////////////////////
		$('#eel-expression-evaluate').click(function() {
			var eelExpression;

			eelExpression = $('#eel-expression').val();
			$.post(window.location.href, {
				'__typo3-typoscript-debugger-eelExpression': eelExpression,
				'__typo3-typoscript-debugger-currentArrayPath': $('.details').attr('data-arraypath')
			}, function(data) {
				$('#eel-result').text(data);
			});
		});
	});
})(Channel, jQuery, Handlebars, style_html);