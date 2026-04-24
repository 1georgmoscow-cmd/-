import asyncio
from aiogram import Bot, Dispatcher, types
from aiogram.types import ReplyKeyboardMarkup, KeyboardButton
from aiogram.fsm.state import StatesGroup, State
from aiogram.fsm.context import FSMContext
from aiogram.filters import Command
from aiogram.fsm.storage.memory import MemoryStorage
from datetime import datetime, timedelta
from apscheduler.schedulers.asyncio import AsyncIOScheduler

TOKEN = "8370999105:AAGIb7jmVF4E3RiCQM4GjcpZGVAbvlFxekw"

bot = Bot(token=TOKEN)
dp = Dispatcher(storage=MemoryStorage())
scheduler = AsyncIOScheduler()
scheduler.start()

# ---------------- СОСТОЯНИЯ ----------------
class Booking(StatesGroup):
    service = State()
    master = State()
    date = State()
    time = State()

# ---------------- ДАННЫЕ ----------------
services = ["Стрижка", "Маникюр", "Педикюр"]
masters = ["Анна", "Мария", "Ольга"]

# ---------------- СТАРТ ----------------
@dp.message(Command("start"))
async def start(message: types.Message, state: FSMContext):
    kb = ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=s)] for s in services],
        resize_keyboard=True
    )
    await message.answer("Выберите услугу:", reply_markup=kb)
    await state.set_state(Booking.service)

# ---------------- ВЫБОР УСЛУГИ ----------------
@dp.message(Booking.service)
async def choose_service(message: types.Message, state: FSMContext):
    await state.update_data(service=message.text)

    kb = ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=m)] for m in masters],
        resize_keyboard=True
    )
    await message.answer("Выберите мастера:", reply_markup=kb)
    await state.set_state(Booking.master)

# ---------------- ВЫБОР МАСТЕРА ----------------
@dp.message(Booking.master)
async def choose_master(message: types.Message, state: FSMContext):
    await state.update_data(master=message.text)

    # ближайшие 7 дней
    dates = [(datetime.now() + timedelta(days=i)).strftime("%d-%m") for i in range(7)]

    kb = ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=d)] for d in dates],
        resize_keyboard=True
    )
    await message.answer("Выберите дату:", reply_markup=kb)
    await state.set_state(Booking.date)

# ---------------- ВЫБОР ДАТЫ ----------------
@dp.message(Booking.date)
async def choose_date(message: types.Message, state: FSMContext):
    await state.update_data(date=message.text)

    times = ["12:00", "14:00", "16:00", "18:00"]

    kb = ReplyKeyboardMarkup(
        keyboard=[[KeyboardButton(text=t)] for t in times],
        resize_keyboard=True
    )
    await message.answer("Выберите время:", reply_markup=kb)
    await state.set_state(Booking.time)

# ---------------- ВЫБОР ВРЕМЕНИ ----------------
@dp.message(Booking.time)
async def choose_time(message: types.Message, state: FSMContext):
    data = await state.update_data(time=message.text)
    user_data = await state.get_data()

    text = (
        f"Запись подтверждена:\n"
        f"Услуга: {user_data['service']}\n"
        f"Мастер: {user_data['master']}\n"
        f"Дата: {user_data['date']}\n"
        f"Время: {user_data['time']}"
    )

    await message.answer(text)

    # ----------- ПАРСИНГ ДАТЫ -----------
    date_str = user_data['date'] + f"-{datetime.now().year} {user_data['time']}"
    booking_time = datetime.strptime(date_str, "%d-%m-%Y %H:%M")

    # ----------- УВЕДОМЛЕНИЯ -----------
    scheduler.add_job(
        send_reminder,
        "date",
        run_date=booking_time - timedelta(hours=24),
        args=[message.chat.id, "Напоминание: запись через 24 часа"]
    )

    scheduler.add_job(
        send_reminder,
        "date",
        run_date=booking_time - timedelta(hours=2),
        args=[message.chat.id, "Напоминание: запись через 2 часа"]
    )

    await state.clear()

# ---------------- ФУНКЦИЯ НАПОМИНАНИЯ ----------------
async def send_reminder(chat_id, text):
    await bot.send_message(chat_id, text)

# ---------------- ЗАПУСК ----------------
async def main():
    await dp.start_polling(bot)

if name == "__main__":
    asyncio.run(main())
