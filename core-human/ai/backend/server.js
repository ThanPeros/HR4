const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());

// Database connection
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'dummy_hr4'
};

// Routes
app.use('/api/predict', require('./routes/prediction'));

// Get employee data for training
app.get('/api/employees', async (req, res) => {
  try {
    const connection = await mysql.createConnection(dbConfig);
    
    const [rows] = await connection.execute(`
      SELECT 
        e.id,
        e.age,
        e.gender,
        e.department,
        e.job_title,
        e.employment_status,
        e.salary,
        e.overtime_hours,
        e.performance_rating,
        DATEDIFF(COALESCE(e.termination_date, CURDATE()), e.hire_date) as tenure_days,
        CASE WHEN e.status = 'Inactive' THEN 1 ELSE 0 END as left_company,
        COUNT(DISTINCT ph.id) as position_changes,
        COUNT(DISTINCT br.id) as bonus_count,
        COALESCE(AVG(br.amount), 0) as avg_bonus,
        COUNT(DISTINCT sr.id) as salary_records_count
      FROM employees e
      LEFT JOIN position_history ph ON e.id = ph.employee_id
      LEFT JOIN bonus_records br ON e.id = br.employee_id
      LEFT JOIN salary_records sr ON e.id = sr.employee_id
      GROUP BY e.id
    `);
    
    await connection.end();
    res.json(rows);
  } catch (error) {
    console.error('Database error:', error);
    res.status(500).json({ error: 'Failed to fetch employee data' });
  }
});

app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});