/* ================= PROCESSING ================= */
const modal = document.querySelector('.modal');
const overlay = document.querySelector('.processing-overlay');
const progress = document.querySelector('.progress-fill');
const mainContent = document.querySelector('.main-content');

function startProcessing() {
  modal.classList.add('processing-active');
  let percent = 0;
  progress.style.width = '0%';

  const timer = setInterval(() => {
    percent++;
    progress.style.width = percent + '%';

    if (percent >= 100) {
      clearInterval(timer);
      setTimeout(endProcessing, 300);
    }
  }, 3);
}

function endProcessing() {
  modal.classList.remove('processing-active');
  overlay.style.display = 'none';
  mainContent.style.display = 'block';
}

startProcessing();

/* ================= LOADING TEXT ================= */
const messages = [
  "Finding the best payment route for you...",
  "Securing your transaction…",
  "Optimizing approval chances…"
];
const loadingText = document.getElementById('loadingText');
let msgIndex = 0;

setInterval(() => {
  msgIndex = (msgIndex + 1) % messages.length;
  loadingText.textContent = messages[msgIndex];
}, 2200);

/* ================= ACCORDION ================= */
document.querySelectorAll('.summary').forEach(card => {
  const header = card.querySelector('.toggle-summary');
  if (!header) return;

  header.addEventListener('click', () => {
    card.classList.toggle('open');
  });
});

/* ================= EDIT / SAVE ================= */
document.querySelectorAll('.edit-icon').forEach(icon => {
  icon.addEventListener('click', e => {
    e.stopPropagation();
    const card = icon.closest('.summary');
    card.classList.add('open', 'editing');
  });
});

document.querySelectorAll('.save-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    e.preventDefault();
    btn.closest('.summary').classList.remove('editing');
  });
});

/* ================= STEPPER ================= */
const steps = document.querySelectorAll('.step');
const backBtn = document.querySelector('.back-btn');
// const footer = document.querySelector('.footer-green-border');

const sections = [
  document.querySelector('.main-content'),
  document.querySelector('.payment-method-content'),
  document.querySelector('.Pay-content')
];

let currentStep = 0;

function updateStep(index) {
  steps.forEach((step, i) => {
    step.className = 'step';

    if (i < index) step.classList.add('green');
    if (i === index) {
      step.classList.add('active');
      if (index === 2) step.classList.add('green');
      if (index === 1) step.classList.add('green');
    }
  });

  sections.forEach(sec => sec.style.display = 'none');
  sections[index].style.display = 'block';

  modal.classList.toggle('compact-modal', index > 0);
  backBtn.classList.toggle('show', index > 0);
}



const proceedBtn = document.querySelector('.proceed-btn');
const modalBody = document.querySelector('.modal-body');
const footer = document.querySelector('.footer-green-border');
const completeProcess = document.querySelector('.complete-process');

proceedBtn.addEventListener('click', () => {

  // LAST STEP
  if (currentStep === 2) {

    modalBody.style.display = 'none';
    footer.style.display = 'none';
    backBtn.style.opacity = '0';

    completeProcess.style.display = 'flex';

    // optional: disable further clicks
    proceedBtn.disabled = true;
    return;
  }

  // NORMAL FLOW
  currentStep++;
  updateStep(currentStep);
});



backBtn.addEventListener('click', () => {
  if (currentStep > 0) currentStep--;
  updateStep(currentStep);
});

updateStep(0);


document.querySelectorAll('.payment-option').forEach(option => {
  option.addEventListener('click', () => {
   
    document.querySelectorAll('.payment-option')
      .forEach(o => o.classList.remove('active'));

    option.classList.add('active');
    option.querySelector('input').checked = true;
    const cardNote = document.querySelector('.card-note');

    if (cardNote) {
      setTimeout(() => {
        cardNote.style.opacity = '0';
        cardNote.style.transition = 'opacity 0.4s ease';

        setTimeout(() => {
          cardNote.style.display = 'none';
        }, 400);

      }, 2000);
    }
  });
});


const creditCard = document.getElementById('credit-card');
const debitCard = document.getElementById('debit-card');
const coinbase = document.getElementById('coinbase');

const card = document.querySelector('.patment-card .payment-option');


const paymentMethod = document.querySelector('.payment-method');
const paymentCard = document.querySelector('.patment-card');
const paymentInfo = document.querySelector('.payment-info');

// DEFAULT STATE
paymentMethod.style.display = 'block';
paymentCard.style.display = 'none';
paymentInfo.style.display = 'none';

// CREDIT CARD CLICK
creditCard.addEventListener('click', () => {
paymentMethod.style.display = 'none';
paymentCard.style.display = 'block';
paymentInfo.style.display = 'none';
});

// DEBIT CARD CLICK
debitCard.addEventListener('click', () => {
paymentMethod.style.display = 'none';
paymentCard.style.display = 'none';
paymentInfo.style.display = 'block';
});

// COINBASE CLICK
coinbase.addEventListener('click', () => {
paymentMethod.style.display = 'none';
paymentCard.style.display = 'none';
paymentInfo.style.display = 'block';
});

// ALL CARD CLICK
card.addEventListener('click', () => {
paymentMethod.style.display = 'none';
paymentCard.style.display = 'none';
paymentInfo.style.display = 'block';
});


const togglePay = document.getElementById('togglePay');
  const sheet = document.getElementById('paySummary');
  const handle = sheet.querySelector('.sheet-handle');

  // Open / Close from "You'll Pay"
  togglePay.addEventListener('click', () => {
    sheet.classList.toggle('open');
  });

  // Close when handle is clicked
handle.addEventListener('click', () => {
  sheet.classList.remove('open');
});
