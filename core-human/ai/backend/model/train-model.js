const tf = require('@tensorflow/tfjs-node');
const mysql = require('mysql2/promise');
require('dotenv').config();

const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'dummy_hr4'
};

async function trainModel() {
  try {
    // Fetch training data
    const connection = await mysql.createConnection(dbConfig);
    
    const [rows] = await connection.execute(`
      SELECT 
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
        COALESCE(AVG(br.amount), 0) as avg_bonus
      FROM employees e
      LEFT JOIN position_history ph ON e.id = ph.employee_id
      LEFT JOIN bonus_records br ON e.id = br.employee_id
      WHERE e.hire_date IS NOT NULL
      GROUP BY e.id
    `);
    
    await connection.end();
    
    // Prepare features and labels
    const { features, labels } = prepareTrainingData(rows);
    
    // Create and train model
    const model = createModel(features[0].length);
    
    console.log('Starting model training...');
    
    await model.fit(tf.tensor2d(features), tf.tensor1d(labels), {
      epochs: 100,
      validationSplit: 0.2,
      callbacks: {
        onEpochEnd: (epoch, logs) => {
          console.log(`Epoch ${epoch + 1}: loss = ${logs.loss.toFixed(4)}, accuracy = ${logs.acc.toFixed(4)}`);
        }
      }
    });
    
    // Save model
    await model.save('file://./model/attrition-model');
    console.log('Model trained and saved successfully!');
    
  } catch (error) {
    console.error('Training error:', error);
  }
}

function prepareTrainingData(employees) {
  const features = [];
  const labels = [];
  
  const departmentMap = {
    'IT': 0, 'HR': 1, 'Finance': 2, 'Marketing': 3, 'Operations': 4
  };
  
  const employmentStatusMap = {
    'Full-Time': 0, 'Part-Time': 1, 'Contract': 2, 'Probation': 3
  };
  
  const genderMap = {
    'Male': 0, 'Female': 1, 'Other': 2
  };
  
  employees.forEach(emp => {
    if (emp.age && emp.salary) { // Ensure required fields exist
      features.push([
        emp.age / 100,
        genderMap[emp.gender] || 0,
        departmentMap[emp.department] || 0,
        employmentStatusMap[emp.employment_status] || 0,
        emp.salary / 100000,
        (emp.overtime_hours || 0) / 40,
        (emp.performance_rating || 5) / 10,
        (emp.tenure_days || 0) / 3650,
        (emp.position_changes || 0) / 10,
        (emp.bonus_count || 0) / 10,
        (emp.avg_bonus || 0) / 50000
      ]);
      
      labels.push(emp.left_company);
    }
  });
  
  return { features, labels };
}

function createModel(inputShape) {
  const model = tf.sequential();
  
  model.add(tf.layers.dense({
    units: 64,
    activation: 'relu',
    inputShape: [inputShape]
  }));
  
  model.add(tf.layers.dropout({ rate: 0.3 }));
  
  model.add(tf.layers.dense({
    units: 32,
    activation: 'relu'
  }));
  
  model.add(tf.layers.dropout({ rate: 0.3 }));
  
  model.add(tf.layers.dense({
    units: 1,
    activation: 'sigmoid'
  }));
  
  model.compile({
    optimizer: 'adam',
    loss: 'binaryCrossentropy',
    metrics: ['accuracy']
  });
  
  return model;
}

// Run training if called directly
if (require.main === module) {
  trainModel();
}

module.exports = { trainModel, prepareTrainingData, createModel };