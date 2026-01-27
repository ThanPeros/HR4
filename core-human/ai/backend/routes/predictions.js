const express = require('express');
const router = express.Router();
const mysql = require('mysql2/promise');
require('dotenv').config();

const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'dummy_hr4'
};

// Get prediction for specific employee
router.post('/', async (req, res) => {
  try {
    const { employeeId } = req.body;
    
    const connection = await mysql.createConnection(dbConfig);
    
    const [employeeData] = await connection.execute(`
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
        COUNT(DISTINCT ph.id) as position_changes,
        COUNT(DISTINCT br.id) as bonus_count,
        COALESCE(AVG(br.amount), 0) as avg_bonus
      FROM employees e
      LEFT JOIN position_history ph ON e.id = ph.employee_id
      LEFT JOIN bonus_records br ON e.id = br.employee_id
      WHERE e.id = ?
      GROUP BY e.id
    `, [employeeId]);
    
    await connection.end();
    
    if (employeeData.length === 0) {
      return res.status(404).json({ error: 'Employee not found' });
    }
    
    // Prepare features for prediction
    const features = prepareFeatures(employeeData[0]);
    
    res.json({
      employee: employeeData[0],
      features: features,
      message: 'Features prepared for prediction'
    });
    
  } catch (error) {
    console.error('Prediction error:', error);
    res.status(500).json({ error: 'Prediction failed' });
  }
});

function prepareFeatures(employee) {
  // Convert categorical data to numerical
  const departmentMap = {
    'IT': 0, 'HR': 1, 'Finance': 2, 'Marketing': 3, 'Operations': 4
  };
  
  const employmentStatusMap = {
    'Full-Time': 0, 'Part-Time': 1, 'Contract': 2, 'Probation': 3
  };
  
  const genderMap = {
    'Male': 0, 'Female': 1, 'Other': 2
  };
  
  return [
    employee.age / 100, // Normalize age
    genderMap[employee.gender] || 0,
    departmentMap[employee.department] || 0,
    employmentStatusMap[employee.employment_status] || 0,
    employee.salary / 100000, // Normalize salary
    employee.overtime_hours / 40, // Normalize overtime
    employee.performance_rating / 10, // Normalize performance
    employee.tenure_days / 3650, // Normalize tenure (10 years max)
    employee.position_changes / 10, // Normalize position changes
    employee.bonus_count / 10, // Normalize bonus count
    employee.avg_bonus / 50000 // Normalize average bonus
  ];
}

module.exports = router;