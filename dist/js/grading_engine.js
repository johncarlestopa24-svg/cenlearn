/**
 * CenLearn LMS — Client-side Grading Engine
 *
 * Standardization Pipeline:
 * RAW SCORE -> TRANSMUTED GRADE -> COMPONENT WEIGHT -> FINAL GRADE
 *
 * Transmutation Formula:
 * Transmuted Grade = (Raw Score / Total Items) * 50 + 50
 */

var CenLearnGrading = (function() {
  var DEFAULT_WEIGHTS = {
    performance_pct: 20,
    exam_pct:        40,
    written_pct:     20, // Quiz
    deportment_pct:  10,
    attendance_pct:  10
  };

  function calculateTransmutedGrade(rawScore, totalItems) {
    if (rawScore === null || rawScore === undefined || isNaN(rawScore)) return null;
    var total = parseFloat(totalItems);
    if (isNaN(total) || total <= 0) return null;
    var raw = Math.max(0, parseFloat(rawScore));
    var transmuted = (raw / total) * 50 + 50;
    return Math.round(transmuted * 100) / 100;
  }

  function calculateWeightedContribution(transmutedGrade, weightPct) {
    if (transmutedGrade === null || transmutedGrade === undefined || isNaN(transmutedGrade)) return 0.00;
    var w = parseFloat(weightPct) / 100.0;
    if (isNaN(w)) return 0.00;
    var contrib = parseFloat(transmutedGrade) * w;
    return Math.round(contrib * 100) / 100;
  }

  function validateWeights(weights) {
    var p = parseFloat(weights.performance_pct !== undefined ? weights.performance_pct : DEFAULT_WEIGHTS.performance_pct) || 0;
    var e = parseFloat(weights.exam_pct !== undefined ? weights.exam_pct : DEFAULT_WEIGHTS.exam_pct) || 0;
    var q = parseFloat(weights.written_pct !== undefined ? weights.written_pct : DEFAULT_WEIGHTS.written_pct) || 0;
    var d = parseFloat(weights.deportment_pct !== undefined ? weights.deportment_pct : DEFAULT_WEIGHTS.deportment_pct) || 0;
    var a = parseFloat(weights.attendance_pct !== undefined ? weights.attendance_pct : DEFAULT_WEIGHTS.attendance_pct) || 0;

    var sum = Math.round((p + e + q + d + a) * 100) / 100;
    return {
      valid: sum === 100,
      sum: sum,
      error: sum === 100 ? null : 'Weights must total exactly 100%. Current total: ' + sum + '%'
    };
  }

  return {
    DEFAULT_WEIGHTS: DEFAULT_WEIGHTS,
    calculateTransmutedGrade: calculateTransmutedGrade,
    calculateWeightedContribution: calculateWeightedContribution,
    validateWeights: validateWeights
  };
})();
