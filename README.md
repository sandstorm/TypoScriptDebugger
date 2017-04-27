# Neos Neos Fusion Debugger

## Features:

- adds button "Activate debugger" at the final end of all output
- if activated, opens the debugger in a new window
- shows the Fusion rendering tree
- when hovering over the rendering tree, you get more information
  about data types, Fusion object configuration, the current context,
  and the generated output.
- when hovering over the rendering tree, the corresponding page parts
  on the webpage are highlighted
- this also works the other way round: hover over elements on the page
  and see the corresponding Fusion element highlighted
- when hovering over the context variables in the details-column,
  you see the tree nodes in the rendering which are using exactly
  the same context.

## TODOs

- more meaningful output of Fusion Configuration, especially support
  for Eel Expressions, and the prototype/override chain
- the displayed Fusion should have references pointing back to the
  files where it has been defined.

To enable this feature, you also need the corresponding change
for Neos.Fusion (as long as it is not merged yet):

  https://review.typo3.org/#/c/17371/

I'm happy for just a few hours of work :-)

Thanks to Mattias Nilsson for doing the styling of this debugger!
