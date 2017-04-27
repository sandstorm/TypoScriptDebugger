(function(Channel) {
	// This file is loaded at the end of a page; and we cannot rely on any JS library being loaded except jschannel.
	var css, button, channel, debuggerWindow, windowSettings = 'status=0,toolbar=0,location=0,menubar=0,directories=0,width=1024,height=768,scrollbars=1', highlightedNodes = [], selectedNodes = [];

	////////////////////////////////////////////////
	// OPEN/CLOSE CHANNEL
	////////////////////////////////////////////////
	function openChannel() {
		debuggerWindow = window.open(fusion_debugger_remote_url, 'neos-fusion-debugger', windowSettings)
		window.sessionStorage['neos-fusion-debugger-active'] = true;

		channel = Channel.build({
			window: debuggerWindow,
			origin: window.location.protocol + '//' + window.location.host,
			scope: 'neos-fusion-debugger'
		});

		channel.bind('highlightElement', function(trans, token) {
			highlightElement(token, false);
		});
		channel.bind('selectElement', function(trans, token) {
			highlightElement(token, true);
		});
		channel.bind('unhighlightElements', function(trans) {
			unhighlightAllNodes(false);
		});

		channel.call({
			method: 'updateEvaluationTrace',
			params: fusion_debugger_evaluation_trace,
			success: function() {
			}
		});

		channel.bind('activateInspectMode', function(trans) {
			document.addEventListener('mousemove', mouseMoveHandler);
			document.addEventListener('click', mouseClickHandler);
		});

			// When the channel is open, we directly start with activating the inspect mode.
		document.addEventListener('mousemove', mouseMoveHandler);
		document.addEventListener('click', mouseClickHandler);


		channel.bind('deactivateInspectMode', function(trans) {
			document.removeEventListener('mousemove', mouseMoveHandler);
			document.removeEventListener('click', mouseClickHandler);
		});

		button.removeChild(button.firstChild);
		button.appendChild(document.createTextNode('Deactivate Fusion Debugger'));
	}

	function closeChannel() {
		debuggerWindow.close();
		channel = null;
		delete window.sessionStorage['neos-fusion-debugger-active'];
		button.removeChild(button.firstChild);
		button.appendChild(document.createTextNode('Activate Fusion Debugger'));
		document.removeEventListener('mousemove', mouseMoveHandler);
		document.removeEventListener('click', mouseClickHandler);
		unhighlightAllNodes(false);
		unhighlightAllNodes(true);
	}

	////////////////////////////////////////////////
	// HELPER: Highlighing an element
	////////////////////////////////////////////////
	// if select=true, the highlighting occurs in solid color. else, it just happens "on hover"
	function highlightElement(token, select) {
		var startNode, endNode, currentNode;

		unhighlightAllNodes(select);
		findStartAndEndNode(window.document);
		if (!startNode || !endNode) {
			console.warn("Start and end node could not be found", startNode, endNode);
			return;
		}

		if (startNode.parentNode === endNode.parentNode) {
				// Siblings / Well-formed: we can easily highlight all nodes in-between
			currentNode = startNode.nextSibling;
			while (currentNode != endNode) {
				if (currentNode.nodeType == 1) {
						// Element Node
					if (select) {
						currentNode.className += ' neos-fusion-debugger-selected';
						selectedNodes.push(currentNode);
					} else {
						currentNode.className += ' neos-fusion-debugger-highlighted';
						highlightedNodes.push(currentNode);
					}
				}

				currentNode = currentNode.nextSibling;
			}
		}

		////// HELPERS

		function findStartAndEndNode(contextNode) {
			var childNodes, childNodeLength, i;

			if (contextNode.nodeType === 8) {
					// COMMENT Node
				if (contextNode.data === 'BEGIN_' + token) {
					startNode = contextNode;
				} else if (contextNode.data === 'END_' + token) {
					endNode = contextNode;
				}
			}

			if (contextNode.childNodes) {
				childNodes = contextNode.childNodes;
				childNodeLength = childNodes.length;
				for (i=0; i < childNodeLength; i++) {
					findStartAndEndNode(childNodes[i]);
				}
			}
		}
	}
	function unhighlightAllNodes(select) {
			var nodes = (select ? selectedNodes : highlightedNodes );
			var i, len = nodes.length, node;
			for (i=0; i < len; i++) {
				node = nodes[i];
				if (select) {
					node.className = node.className.replace(/ neos-fusion-debugger-selected/, '')
				} else {
					node.className = node.className.replace(/ neos-fusion-debugger-highlighted/, '')
				}
			}
			if (select) {
				selectedNodes = [];
			} else {
				highlightedNodes = [];
			}
		}

	////////////////////////////////////////////////
	// HELPER: Inspect mode
	////////////////////////////////////////////////
	var inspectModeTimer = null, lastTarget = null;
	function mouseMoveHandler(e) {
		var token;
		if (lastTarget !== e.target) {
			lastTarget = e.target;
			if (inspectModeTimer) window.clearTimeout(inspectModeTimer);
		} else {
				// The target has not changed; so we just continue with the given timer.
			return;
		}

		inspectModeTimer = window.setTimeout(function() {
			inspectModeTimer = null;
			token = findEnclosingToken(e.target);
			if (token) {
				highlightElement(token);
				channel.call({
					method: 'highlightElement',
					params: token,
					success: function() {
					}
				});
			}
		}, 20);
	}

	function mouseClickHandler(e) {
		var token = findEnclosingToken(e.target);
		if (token) {
			highlightElement(token, true);
			channel.call({
				method: 'selectElement',
				params: token,
				success: function() {
				}
			});
		}
	}

	function findEnclosingToken(node) {
		var tokenList = [], tokenListLength, currentNodeIndex, i, a, x, y;

			// First, build a list of all tokens (BEGIN_... and END_...) in the document,
			// and additionally mark the current "node" as "CURRENTNODE"
			// Example List: BEGIN_TOKEN1, BEGIN_TOKEN2, END_TOKEN2, CURRENTNODE, END_TOKEN1
		buildTokenList(document);

			// Now, let's find the innermost balanced BEGIN_* and END_* token around CURRENTNODE.
			// This is the token which is responsible for rendering CURRENTNODE.
		currentNodeIndex = tokenList.indexOf('CURRENTNODE');
		tokenListLength = tokenList.length;
		for (i = currentNodeIndex-1; i>=0; i--) {
			x = tokenList[i];
			if (x.match(/^BEGIN_/)) {
				for (a = currentNodeIndex + 1; a < tokenListLength; a++) {
					y = tokenList[a];
					if (y.match(/^END_/) && x.substr(6) == y.substr(4)) {
							// SUCCESS, we've found the right enclosing token,
							// so we return it (without BEGIN_/END_).
						return x.substr(6);
					}
				}
			}
		}

		function buildTokenList(contextNode) {
			var childNodes, childNodeLength, i;
			if (contextNode.nodeType === 8 && (contextNode.data.match(/^BEGIN_/) || contextNode.data.match(/^END_/))) {
				tokenList.push(contextNode.data);
			} else if(contextNode === node) {
				tokenList.push('CURRENTNODE');
			}

			if (contextNode.childNodes) {
				childNodes = contextNode.childNodes;
				childNodeLength = childNodes.length;
				for (i=0; i < childNodeLength; i++) {
					buildTokenList(childNodes[i]);
				}
			}
		}

		return null;
	}

	window.a = findEnclosingToken;

	////////////////////////////////////////////////
	// ADD UI CONTROLS TO PAGE
	////////////////////////////////////////////////
	button = document.createElement('button');
	button.appendChild(document.createTextNode('Activate Fusion Debugger'));

	button.addEventListener('click', function() {
		if (channel) {
			closeChannel();
		} else {
			openChannel();
		}
	});

	css = document.createElement('style');
	css.type = 'text/css';

	css.innerHTML = '.neos-fusion-debugger-highlighted { outline: 4px solid #FFCF99;}' + "\n";
	css.innerHTML += '.neos-fusion-debugger-selected { outline: 4px solid #FF8700;}'
	document.body.appendChild(css);

	if (window.sessionStorage['neos-fusion-debugger-active']) {
		openChannel();
	}

	document.getElementsByTagName('body')[0].appendChild(button);
})(Channel);
