# Chatbot Webhook System

## Setup Instructions

### 1. Database Setup
Run the SQL file to create the database and tables:
```bash
mysql -u root -p < database.sql
```

Or import `database.sql` through phpMyAdmin.

### 2. Database Configuration
Update `config.php` with your database credentials:
- DB_HOST: Your database host (default: localhost)
- DB_USER: Your database username (default: root)
- DB_PASS: Your database password (default: empty)
- DB_NAME: Your database name (default: chatbot_db)

### 3. Implement Your Functions

#### In `functions.php`:

1. **`processIncomingData($data)`** - Extract text and number from POST data
   - Return format: `['text' => 'message', 'number' => 'phone_number']`

2. **`getReplyMessage($incomingMessage, $phone)`** - Generate reply for existing users
   - Return format: Reply message string

3. **`sendMessage($phone, $message)`** - Implement your API call to send messages
   - Add your API endpoint and authentication here

## How It Works

1. Webhook receives POST data
2. Data is passed to `processIncomingData()` to extract text and number
3. System checks if phone number exists in `leads` table
4. If phone exists:
   - Gets reply from `getReplyMessage()`
   - Sends reply via `sendMessage()`
   - Logs the sent message
5. If phone is new:
   - Inserts into `leads` table
   - Logs the received message
6. All messages are saved in `chat_history` table
7. All activities are logged in `message_logs` table with date/time

## Database Tables

- **leads**: Stores unique phone numbers
- **message_logs**: Logs all received and sent messages with timestamps
- **chat_history**: Maintains complete chat history (incoming/outgoing)

## Testing

You can test the webhook by sending POST requests:
```bash
curl -X POST http://localhost/chatbot/webhook.php \
  -H "Content-Type: application/json" \
  -d '{"text":"Hello","number":"1234567890"}'
```

