# CenLearn AI Quiz Generator & Semantic Essay Grading Specification

This document details the LMS Quiz Architecture and System Prompt for AI-driven quiz creation and evaluation in CenLearn.

## Supported Question Types
1. **Single Multiple Choice (`multiple_choice`)**: 1 correct answer chosen from permanent Option IDs.
2. **Multi-Select Multiple Choice (`multi_select`)**: 2+ correct answers with partial credit or exact match.
3. **True or False (`true_false`)**: Binary truth value.
4. **Modified True or False (`modified_true_false`)**: Boolean value + incorrect phrase & replacement correction when FALSE.
5. **Identification (`identification`)**: Direct term/definition with configurable tolerance and alternative acceptable answers.
6. **Enumeration (`enumeration`)**: Multiple expected items, order-agnostic, deduplicated, partial credit.
7. **Matching Type (`matching`)**: Paired items between Column A and Column B keyed by permanent IDs.
8. **Essay (`essay`)**: Module-bound prompt with required concepts, max score, custom rubric, and AI semantic evaluation.

## Key Principles
- **Teacher Module is Source of Truth**: Questions and answers originate strictly from the uploaded module.
- **Same Quiz Guarantee**: Shuffling affects only display presentation via per-student deterministic seeds.
- **Semantic Understanding**: Essays evaluate student comprehension and concept presence, accepting synonyms and paraphrasing without requiring verbatim quotation.
- **Teacher Authority**: AI scores are suggestions; teachers have full override authority over scores, rubrics, and feedback.
