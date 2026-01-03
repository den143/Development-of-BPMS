# Algorithm Description for Scoring Module

## 1. Algorithm Description

The scoring system calculates a final score for each contestant in a given round by aggregating scores from multiple judges across various segments. The system ensures fairness by strictly validating that all assigned judges have submitted scores for all criteria before generating a final average. If any score is missing, the contestant's result is marked as **Pending**, and no partial average is displayed.

The core logic involves:
1.  **Fetching Data**: Retrieving active judges, contestants, segments, criteria, and raw scores.
2.  **Validation**: Checking for the existence of scores for every criteria from every judge.
3.  **Aggregation**: Summing criteria scores, applying segment weights, and summing judge totals.
4.  **Averaging**: Calculating the mean score across all judges.

## 2. Data Flow

The data flows from the database tables to the final calculation as follows:

1.  **Inputs**:
    *   `Round ID`: The specific round being calculated.
    *   `judges`: Active judges linked to the event.
    *   `segments`: Scoring categories (e.g., Swimsuit, Gown) with `weight_percentage`.
    *   `criteria`: Specific metrics within segments (e.g., Poise, Beauty) with `max_score`.
    *   `scores`: Raw values (`decimal(5,2)`) submitted by judges.

2.  **Processing**:
    *   For each **Contestant**:
        *   Iterate through each **Judge**.
        *   For each **Segment**:
            *   Retrieve all **Criteria** for that segment.
            *   **Check**: Does a score exist for this `[contestant][judge][criteria]`?
            *   If **No**: Mark Judge as `Pending`.
            *   If **Yes**: Sum the raw score into `SegmentRawScore`.
            *   Calculate `WeightedSegmentScore`.
        *   Sum `WeightedSegmentScores` to get `JudgeRoundTotal`.
    *   If **Any** Judge is `Pending` (or active judge count mismatch), set Contestant Status to `Pending`.
    *   Else, `FinalScore` = Average of `JudgeRoundTotal`s.

3.  **Output**:
    *   A ranked list of contestants with final scores (formatted to 2 decimal places) or a "Pending" status.

## 3. Weight Application Formula

The calculation uses a weighted sum approach.

**Definitions:**
*   $S_{j,s,c}$: Score given by Judge $j$ for Segment $s$, Criteria $c$.
*   $W_s$: Weight percentage of Segment $s$ (e.g., 40 for 40%).
*   $Score_{j,s}$: Total raw score for Judge $j$ in Segment $s$.
*   $Total_{j}$: Total weighted score for Judge $j$.
*   $N$: Total number of active judges.
*   $FinalScore$: The final average score.

**Formulas:**

1.  **Segment Raw Score**:
    $$ Score_{j,s} = \sum_{c \in s} S_{j,s,c} $$

2.  **Judge Round Total**:
    $$ Total_{j} = \sum_{s} \left( Score_{j,s} \times \frac{W_s}{100} \right) $$

3.  **Final Round Score**:
    $$ FinalScore = \frac{\sum_{j=1}^{N} Total_{j}}{N} $$

*Note: The system handles floating-point precision by using PHP's native float types during calculation and only rounding (via `number_format`) for the final display.*

## 4. Handling of Null/Pending Scores

To ensure integrity, the system treats missing data as a "blocking" state rather than a zero value.

*   **Strict Validation**: The algorithm iterates through every expected score entry (Contestant × Judge × Criteria).
*   **No Zeros for Nulls**: A missing score (`NULL` or no row in database) is **never** treated as 0.00. It is flagged immediately.
*   **Pending Propagation**:
    *   If Judge A has not scored Criteria X, Judge A's total is `Pending`.
    *   If Judge A is `Pending`, the entire Contestant's Round Score is `Pending`.
    *   The `FinalScore` is set to `0` (internally) and displayed as "Pending" to the frontend.
    *   This prevents "partial averages" (e.g., averaging 4 judges when 5 are required) which would skew the ranking.
