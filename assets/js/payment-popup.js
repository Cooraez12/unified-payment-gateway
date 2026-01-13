jQuery(function ($) {

  /* ================= PROCESSING ================= */
  const $modal       = $('#unified-payment-popup .modal');
  const $overlay     = $('.processing-overlay');
  const $progress    = $('.progress-fill');
  const $mainContent = $('.main-content');

  function startProcessing() {
    let percent = 0;

    $modal.addClass('processing-active');
    $progress.css('width', '0%');

    const timer = setInterval(() => {
      percent++;
      $progress.css('width', percent + '%');

      if (percent >= 100) {
        clearInterval(timer);
        setTimeout(endProcessing, 300);
      }
    }, 3);
  }

  function endProcessing() {
    $modal.removeClass('processing-active');
    $overlay.hide();
    $mainContent.show();
  }

  startProcessing();

  /* ================= LOADING TEXT ================= */
  const messages = [
    "Finding the best payment route for you...",
    "Securing your transaction…",
    "Optimizing approval chances…"
  ];

  let msgIndex = 0;
  const $loadingText = $('#loadingText');

  setInterval(() => {
    msgIndex = (msgIndex + 1) % messages.length;
    $loadingText.text(messages[msgIndex]);
  }, 2200);

  /* ================= ACCORDION ================= */
  $(document).on('click', '.toggle-summary', function () {
    $(this).closest('.summary').toggleClass('open');
  });

  /* ================= EDIT / SAVE ================= */
  $(document).on('click', '.edit-icon', function (e) {
    e.stopPropagation();
    $(this).closest('.summary').addClass('open editing');
  });

  $(document).on('click', '.save-btn', function (e) {
    e.preventDefault();
    $(this).closest('.summary').removeClass('editing');
  });

  /* ================= STEPPER ================= */
  const $steps     = $('.step');
  const $backBtn   = $('.back-btn');
  const $sections  = $('.main-content, .payment-method-content, .Pay-content');
  const $modalBody = $('.modal-body');
  const $footer    = $('.footer-green-border');
  const $complete  = $('.complete-process');

  let currentStep = 0;

  function updateStep(index) {
    $steps.removeClass('active green')
      .each(function (i) {
        if (i < index) $(this).addClass('green');
        if (i === index) $(this).addClass('active green');
      });

    $sections.hide().eq(index).show();

    $modal.toggleClass('compact-modal', index > 0);
    $backBtn.toggleClass('show', index > 0);
  }

  updateStep(0);

  $('.proceed-btn').on('click', function () {

    if (currentStep === 2) {
      $modalBody.hide();
      $footer.hide();
      $backBtn.css('opacity', 0);
      $complete.show();
      $(this).prop('disabled', true);
      return;
    }

    currentStep++;
    updateStep(currentStep);
  });

  $backBtn.on('click', function () {
    if (currentStep > 0) {
      currentStep--;
      updateStep(currentStep);
    }
  });

  /* ================= PAYMENT OPTIONS ================= */
  $(document).on('click', '.payment-option', function () {
    $('.payment-option').removeClass('active');
    $(this).addClass('active')
      .find('input[type="radio"]').prop('checked', true);

    const $cardNote = $('.card-note');
    if ($cardNote.length) {
      setTimeout(() => {
        $cardNote.fadeOut(400);
      }, 2000);
    }
  });

  /* ================= PAYMENT METHOD FLOW ================= */
  const $paymentMethod = $('.payment-method');
  const $paymentCard   = $('.patment-card');
  const $paymentInfo   = $('.payment-info');

  function showSection(type) {
    $paymentMethod.hide();
    $paymentCard.hide();
    $paymentInfo.hide();

    if (type === 'card') $paymentCard.show();
    else if (type === 'info') $paymentInfo.show();
    else $paymentMethod.show();
  }

  // Default
  showSection('method');

  $('#credit-card').on('click', () => showSection('card'));
  $('#debit-card, #coinbase').on('click', () => showSection('info'));
  $('.patment-card .payment-option').on('click', () => showSection('info'));

  /* ================= PAY SUMMARY SHEET ================= */
  $('#togglePay').on('click', function () {
    $('#paySummary').toggleClass('open');
  });

  $('#paySummary .sheet-handle').on('click', function () {
    $('#paySummary').removeClass('open');
  });

  /* ================= CLOSE PAYMENT POPUP ================= */
  $(document).on('click', '.payment-popup-close-btn', function () {

    const $popup = $('#unified-payment-popup');

    // Reset UI states (important)
    $popup.hide();
  });

});
