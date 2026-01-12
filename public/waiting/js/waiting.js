const peopleInput = document.getElementById('peopleInput');
const decPeople = document.getElementById('decPeople');
const incPeople = document.getElementById('incPeople');
const phoneInput = document.getElementById('phone');
const consent = document.getElementById('consent');
const queueList = document.getElementById('queueList');
const queueSummary = document.getElementById('queueSummary');
const clock = document.getElementById('clock');
const toast = document.getElementById('toast');
const storeNameEl = document.getElementById('storeName');
const totalTeamsEl = document.getElementById('totalTeams');
const nextTicketEl = document.getElementById('nextTicket');
const boardMain = document.getElementById('boardMain');
const boardEmpty = document.getElementById('boardEmpty');

const phonePattern = /^01[0-9]{8,9}$/; // 국내 휴대폰 10~11자리
const API_BASE = '/waiting/api';

let selectedPeople = null;
let waitlist = [];

async function fetchStoreInfo() {
  const fallback = 'Blue Harbour Dining';
  if (!storeNameEl) return;
  try {
    const res = await fetch(`${API_BASE}/store-info.php`);
    if (!res.ok) throw new Error('bad response');
    const data = await res.json();
    storeNameEl.textContent = data?.name || fallback;
  } catch (e) {
    storeNameEl.textContent = fallback;
  }
}

function showToast(message, tone = 'positive') {
  if (!toast) return;
  toast.textContent = message;
  toast.classList.remove('positive', 'negative', 'show');
  toast.classList.add(tone === 'negative' ? 'negative' : 'positive');
  requestAnimationFrame(() => {
    toast.classList.add('show');
  });
  setTimeout(() => toast.classList.remove('show'), 2400);
}

function formatPhone(value) {
  const digits = value.replace(/[^\d]/g, '').slice(0, 11);
  if (digits.length <= 3) return digits;
  if (digits.length <= 7) return `${digits.slice(0, 3)}-${digits.slice(3)}`;
  if (digits.length === 10) return `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6)}`;
  return `${digits.slice(0, 3)}-${digits.slice(3, 7)}-${digits.slice(7)}`;
}

function renderQueue() {
  const waitingTeams = waitlist.filter((w) => w.status === 'waiting');

  if (waitingTeams.length === 0) {
    if (queueSummary) {
      queueSummary.textContent = '';
      queueSummary.style.display = 'none';
    }
    if (totalTeamsEl) totalTeamsEl.textContent = '0팀';
    if (nextTicketEl) nextTicketEl.textContent = '0팀';
    if (boardMain) boardMain.style.display = 'grid';
    if (boardEmpty) boardEmpty.style.display = 'grid';
    return;
  }

  const earliestTicket = Math.min(...waitingTeams.map((w) => w.ticket));
  const waitingCount = waitingTeams.length;

  if (queueSummary) {
    queueSummary.textContent = `현재 ${waitingCount}팀 대기 · 가장 빠른 대기번호 ${earliestTicket}번`;
    queueSummary.style.display = 'block';
  }
  if (totalTeamsEl) totalTeamsEl.textContent = `${waitingCount}팀`;
  if (nextTicketEl) nextTicketEl.textContent = `${waitingCount}팀`;
  if (boardMain) boardMain.style.display = 'grid';
  if (boardEmpty) boardEmpty.style.display = 'none';
}

async function fetchQueue() {
  try {
    const res = await fetch(`${API_BASE}/reservations.php`);
    if (!res.ok) throw new Error('bad response');
    const data = await res.json();
    waitlist = data?.items || [];
    renderQueue();
  } catch (e) {
    showToast('대기 현황을 불러오지 못했습니다.', 'negative');
  }
}

function setupForm() {
  const peopleControl = document.getElementById('peopleControl');

  function clampPeople(val) {
    const num = Math.max(1, Math.min(30, Number(val) || 0));
    return num;
  }

  peopleInput?.addEventListener('input', (e) => {
    const val = clampPeople(e.target.value);
    e.target.value = val;
  });

  decPeople?.addEventListener('click', () => {
    const val = clampPeople(Number(peopleInput?.value || 1) - 1);
    peopleInput.value = val;
  });

  incPeople?.addEventListener('click', () => {
    const val = clampPeople(Number(peopleInput?.value || 1) + 1);
    peopleInput.value = val;
  });

  phoneInput?.addEventListener('input', (e) => {
    const target = e.target;
    target.value = formatPhone(target.value);
  });

  document.getElementById('waitForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const isEmpty = waitlist.filter((w) => w.status === 'waiting').length === 0;
    console.log('isEmpty', isEmpty);
    const partySize = clampPeople(peopleInput?.value || 0);
    if (!partySize) {
      showToast('인원수를 입력해 주세요.', 'negative');
      peopleControl?.classList.add('hint');
      setTimeout(() => peopleControl?.classList.remove('hint'), 600);
      return;
    }
    const phone = phoneInput.value.replace(/[^\d]/g, '');

    if (isEmpty) {
      showToast('현재 대기팀이 없습니다. 바로 입장 가능합니다.');
      return;
    }

    if (!consent.checked) {
      showToast('개인정보 이용 동의가 필요합니다.', 'negative');
      return;
    }
    if (!phonePattern.test(phone)) {
      showToast('휴대폰 번호 형식을 확인해 주세요. (예: 010-1234-5678)', 'negative');
      phoneInput.focus();
      return;
    }

    try {
      const res = await fetch(`${API_BASE}/reservations.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ people: partySize, phone }),
      });
      if (!res.ok) throw new Error('save failed');
      showToast('등록되었습니다! 잠시만 기다려 주세요.');
      phoneInput.value = '';
      consent.checked = false;
      peopleInput.value = partySize;
      await fetchQueue();
      if (isEmpty) {
        showToast('바로 입장 가능합니다.');
      }
    } catch (err) {
      showToast('등록에 실패했습니다. 잠시 후 다시 시도해주세요.', 'negative');
    }
  });
}

function tickClock() {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  clock.textContent = `${hh}:${mm}`;
}

setupForm();
fetchQueue();
fetchStoreInfo();
tickClock();
setInterval(tickClock, 30000);
setInterval(fetchQueue, 10000);
