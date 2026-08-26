## Tool grouping rule for future additions

When adding new tools, follow this rule:

1. Keep the overall site **category-based** and each category installable as its own mini PWA.
2. If multiple functions use the same input/data and the same primary workflow, **combine them into one mini-app** with tabs/options instead of creating separate apps.
3. Keep tools separate when they have a genuinely different workflow, input model, or user purpose, even if they belong to the same category.
4. Do not create duplicate apps merely because the output/result differs when the underlying workflow is the same.
5. Before adding a new tool, check the existing mini-apps first; add a new function to an existing mini-app when it naturally fits.

Examples: image resize + crop + rotate + flip → one Image Editor; all measurement conversions → one Unit Converter; JSON formatting + JSON conversions → one JSON Toolkit.

## Result action rule

When a tool produces a result, include the relevant actions whenever technically applicable: **Copy**, **Share**, and **Download**. For text/data results ToolPWA provides a consistent result-action bar; for generated files such as images, the Download action should download the actual generated file. Do not add actions that are meaningless for the result.
