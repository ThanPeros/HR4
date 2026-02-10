<?php if ($edit_employee): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 text-primary"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-close"></a>
        </div>
        
        <!-- Profile Header -->
        <div class="card-body bg-light border-bottom text-center py-4">
            <div class="position-relative d-inline-block mb-3">
                <?php if (!empty($edit_employee['photo']) && file_exists(UPLOAD_DIR . $edit_employee['photo'])): ?>
                    <img src="<?php echo UPLOAD_DIR . $edit_employee['photo']; ?>" class="rounded-circle shadow-sm border border-3 border-white" style="width: 100px; height: 100px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle shadow-sm border border-3 border-white bg-white d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                        <i class="fas fa-user fa-3x text-secondary"></i>
                    </div>
                <?php endif; ?>
                <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-<?php echo $edit_employee['status'] === 'Active' ? 'success' : 'secondary'; ?> border border-white">
                    <?php echo $edit_employee['status']; ?>
                </span>
            </div>
            <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($edit_employee['name']); ?></h5>
            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($edit_employee['job_title']); ?> | <?php echo htmlspecialchars($edit_employee['department']); ?></p>
            <p class="text-muted small mb-0 font-monospace"><?php echo htmlspecialchars($edit_employee['employee_id'] ?? 'EMP' . str_pad($edit_employee['id'], 4, '0', STR_PAD_LEFT)); ?></p>
        </div>

        <!-- Navigation Tabs -->
        <div class="card-header bg-white p-0 border-bottom-0">
            <ul class="nav nav-tabs nav-fill card-header-tabs m-0" id="editTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_personal" data-bs-toggle="tab"><i class="fas fa-id-card me-2"></i>Personal</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_employment" data-bs-toggle="tab"><i class="fas fa-briefcase me-2"></i>Employ.</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_compensation" data-bs-toggle="tab"><i class="fas fa-money-bill-wave me-2"></i>Compens.</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_perf" data-bs-toggle="tab"><i class="fas fa-chart-line me-2"></i>Perf.</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_docs" data-bs-toggle="tab"><i class="fas fa-folder-open me-2"></i>201 Files</button></li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content">
                <!-- PERSONAL TAB -->
                <div class="tab-pane fade show active" id="tab_personal">
                    <div class="p-4">
                        <form method="POST" enctype="multipart/form-data" id="profileForm">
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Basic Information</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-12">
                                    <label class="form-label small">Full Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_employee['name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Date of Birth</label>
                                    <input type="date" name="birth_date" class="form-control" value="<?php echo htmlspecialchars($edit_employee['birth_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="Male" <?php echo ($edit_employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($edit_employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Civil Status</label>
                                    <select name="civil_status" class="form-select">
                                        <option value="Single" <?php echo ($edit_employee['civil_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo ($edit_employee['civil_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo ($edit_employee['civil_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Nationality</label>
                                    <input type="text" name="nationality" class="form-control" value="<?php echo htmlspecialchars($edit_employee['nationality'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Photo</label>
                                    <input type="file" name="photo" class="form-control" accept="image/*">
                                </div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Contact Details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit_employee['email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Address</label>
                                    <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($edit_employee['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Changes</button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <!-- Dependents Section -->
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3 d-flex justify-content-between align-items-center">
                            Dependents
                        </h6>
                        <div class="list-group mb-3">
                            <?php foreach ($dependents as $dep): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($dep['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($dep['relationship']); ?> | <?php echo htmlspecialchars($dep['birth_date']); ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger delete-dependent" data-dependent-id="<?php echo $dep['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="dependentForm" class="card bg-light border-0 p-3">
                            <input type="hidden" name="action" value="save_dependent">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            <div class="row g-2">
                                <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name" required></div>
                                <div class="col-6"><input type="text" name="relationship" class="form-control form-control-sm" placeholder="Relationship" required></div>
                                <div class="col-6"><input type="date" name="birth_date" class="form-control form-control-sm"></div>
                                <div class="col-6"><input type="text" name="contact_number" class="form-control form-control-sm" placeholder="Contact #"></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Dependent</button></div>
                            </div>
                        </form>
                        
                         <hr class="my-4">

                        <!-- Emergency Contacts -->
                         <h6 class="text-uppercase text-secondary small fw-bold mb-3">Emergency Contacts</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($emergency_contacts as $contact): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($contact['contact_name']); ?></strong>
                                        <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars($contact['relationship']); ?></span>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($contact['phone']); ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger delete-contact" data-contact-id="<?php echo $contact['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="contactForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_contact">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="text" name="contact_name" class="form-control form-control-sm" placeholder="Name" required></div>
                                <div class="col-6"><input type="text" name="relationship" class="form-control form-control-sm" placeholder="Relationship" required></div>
                                <div class="col-12"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone" required></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Emergency Contact</button></div>
                             </div>
                        </form>
                    </div>
                </div>

                <!-- EMPLOYMENT TAB -->
                <div class="tab-pane fade" id="edit_employment">
                    <div class="p-4">
                        <form method="POST" id="employmentForm" enctype="multipart/form-data">
                             <input type="hidden" name="action" value="update_profile">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             
                             <h6 class="text-uppercase text-secondary small fw-bold mb-3">Employment Details</h6>
                             <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small">Date Hired</label>
                                    <input type="date" name="date_hired" class="form-control" value="<?php echo htmlspecialchars($edit_employee['date_hired'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="Active" <?php echo $edit_employee['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $edit_employee['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Department</label>
                                    <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($edit_employee['department']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Position</label>
                                    <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($edit_employee['job_title']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Manager/Supervisor</label>
                                    <input type="text" name="manager" class="form-control" value="<?php echo htmlspecialchars($edit_employee['manager'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Work Schedule</label>
                                    <input type="text" name="work_schedule" class="form-control" value="<?php echo htmlspecialchars($edit_employee['work_schedule'] ?? ''); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Job Description</label>
                                    <textarea name="job_description" class="form-control" rows="3"><?php echo htmlspecialchars($edit_employee['job_description'] ?? ''); ?></textarea>
                                </div>
                             </div>
                             
                             <h6 class="text-uppercase text-secondary small fw-bold mb-3">Files</h6>
                             <div class="mb-3">
                                 <label class="form-label small">Resume</label>
                                 <input type="file" name="resume_file" class="form-control form-control-sm">
                                 <?php if (!empty($edit_employee['resume_file'])): ?>
                                     <small><a href="<?php echo UPLOAD_DIR . $edit_employee['resume_file']; ?>" target="_blank">View Current</a></small>
                                 <?php endif; ?>
                             </div>
                             
                             <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Changes</button>
                        </form>
                        
                        <hr class="my-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">Work History</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($work_experience as $work): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($work['position']); ?></h6>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($work['company_name']); ?></small>
                                            <small class="text-muted"><?php echo htmlspecialchars($work['date_from']); ?> - <?php echo $work['is_current'] ? 'Present' : htmlspecialchars($work['date_to']); ?></small>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger delete-work" data-work-id="<?php echo $work['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="workForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_work_experience">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="text" name="company_name" class="form-control form-control-sm" placeholder="Company" required></div>
                                <div class="col-6"><input type="text" name="position" class="form-control form-control-sm" placeholder="Position" required></div>
                                <div class="col-6"><input type="date" name="date_from" class="form-control form-control-sm" required></div>
                                <div class="col-6"><input type="date" name="date_to" class="form-control form-control-sm"></div>
                                <div class="col-12"><div class="form-check"><input type="checkbox" name="is_current" value="1" class="form-check-input" id="is_current"><label class="form-check-label small" for="is_current">Current Job</label></div></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add History</button></div>
                             </div>
                        </form>
                    </div>
                </div>
                
                <!-- COMPENSATION TAB -->
                <div class="tab-pane fade" id="tab_compensation">
                    <div class="p-4">
                        <form method="POST" id="compForm">
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Comp & Ben</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Basic Salary</label>
                                    <input type="number" step="0.01" name="salary" class="form-control" value="<?php echo htmlspecialchars($edit_employee['salary']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Pay Grade</label>
                                    <input type="text" name="pay_grade" class="form-control" value="<?php echo htmlspecialchars($edit_employee['pay_grade'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($edit_employee['bank_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Account No.</label>
                                    <input type="text" name="bank_account_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['bank_account_no'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mt-4 mb-3">Government IDs</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6"><label class="form-label small">SSS</label><input type="text" name="sss_no" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['sss_no'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="form-label small">PhilHealth</label><input type="text" name="philhealth_no" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['philhealth_no'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="form-label small">Pag-IBIG</label><input type="text" name="pagibig_no" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['pagibig_no'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="form-label small">TIN</label><input type="text" name="tin_no" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['tin_no'] ?? ''); ?>"></div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">HMO / Benefits</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6"><label class="form-label small">HMO Provider</label><input type="text" name="hmo_provider" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['hmo_provider'] ?? ''); ?>"></div>
                                <div class="col-md-6"><label class="form-label small">HMO Number</label><input type="text" name="hmo_number" class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_employee['hmo_number'] ?? ''); ?>"></div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Changes</button>
                        </form>
                        
                        <hr class="my-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">Salary History</h6>
                        <div class="list-group mb-3">
                             <?php foreach ($salary_history as $hist): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-2">
                                    <div>
                                        <strong><?php echo number_format($hist['amount'], 2); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($hist['type']); ?> | <?php echo htmlspecialchars($hist['effective_date']); ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger delete-salary" data-salary-id="<?php echo $hist['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="salaryForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_salary">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
                                <div class="col-6"><input type="date" name="effective_date" class="form-control form-control-sm" required></div>
                                <div class="col-12"><input type="text" name="type" class="form-control form-control-sm" placeholder="Type (Increase, Adjustment, etc.)"></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Record</button></div>
                             </div>
                        </form>
                    </div>
                </div>

                <!-- PERFORMANCE TAB -->
                <div class="tab-pane fade" id="tab_perf">
                    <div class="p-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">Performance Reviews</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($performance as $perf): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="mb-1">Rating: <?php echo htmlspecialchars($perf['rating']); ?></h6>
                                            <small class="text-muted">Date: <?php echo htmlspecialchars($perf['review_date']); ?></small>
                                            <div class="small mt-1 fst-italic">"<?php echo htmlspecialchars($perf['comments']); ?>"</div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger delete-performance" data-performance-id="<?php echo $perf['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                         <form method="POST" id="performanceForm" class="card bg-light border-0 p-3 mb-4">
                             <input type="hidden" name="action" value="save_performance">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="date" name="review_date" class="form-control form-control-sm" required></div>
                                <div class="col-6"><input type="text" name="rating" class="form-control form-control-sm" placeholder="Rating (e.g. 4.5/5)" required></div>
                                <div class="col-12"><input type="text" name="evaluator" class="form-control form-control-sm" placeholder="Evaluator"></div>
                                <div class="col-12"><textarea name="comments" class="form-control form-control-sm" rows="2" placeholder="Comments"></textarea></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Review</button></div>
                             </div>
                        </form>
                        
                        <hr>
                        
                        <h6 class="text-uppercase text-danger small fw-bold mb-3">Disciplinary Cases</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($disciplinary as $case): ?>
                                <div class="list-group-item border-start border-danger border-3 p-3">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong class="text-danger"><?php echo htmlspecialchars($case['violation']); ?></strong>
                                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($case['status']); ?></span>
                                            <small class="d-block text-muted">Reported: <?php echo htmlspecialchars($case['date_reported']); ?></small>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger delete-disciplinary" data-disciplinary-id="<?php echo $case['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="disciplinaryForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_disciplinary">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-12"><input type="text" name="violation" class="form-control form-control-sm" placeholder="Violation" required></div>
                                <div class="col-6"><input type="date" name="date_reported" class="form-control form-control-sm" required></div>
                                <div class="col-6"><select name="status" class="form-select form-select-sm"><option value="Open">Open</option><option value="Closed">Closed</option></select></div>
                                <div class="col-12"><textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description"></textarea></div>
                                <div class="col-12"><input type="text" name="action_taken" class="form-control form-control-sm" placeholder="Action Taken"></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-danger w-100">Record Case</button></div>
                             </div>
                        </form>
                    </div>
                </div>

                <!-- 201 FILES TAB -->
                <div class="tab-pane fade" id="tab_docs">
                    <div class="p-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">201 Files & Certificates</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($documents as $doc): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3 text-secondary"><i class="fas fa-file-alt fa-2x"></i></div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($doc['document_name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($doc['document_type']); ?> | <?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></small>
                                            </div>
                                        </div>
                                        <div class="btn-group">
                                            <a href="<?php echo UPLOAD_DIR . $doc['file_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                                            <button class="btn btn-sm btn-outline-danger delete-document" data-document-id="<?php echo $doc['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" id="documentForm" class="card bg-light border-0 p-3">
                            <input type="hidden" name="action" value="save_document">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            <div class="row g-2">
                                <div class="col-12"><input type="text" name="document_name" class="form-control form-control-sm" placeholder="Document Name" required></div>
                                <div class="col-12">
                                    <select name="document_type" class="form-select form-select-sm" required>
                                        <option value="" selected disabled>Select Type...</option>
                                        <option value="Resume/CV">Resume/CV</option>
                                        <option value="Application Form">Application Form</option>
                                        <option value="Contract">Contract</option>
                                        <option value="Job Offer">Job Offer</option>
                                        <option value="Certificate">Certificate</option>
                                        <option value="Memo">Memo</option>
                                        <option value="Evaluation">Evaluation</option>
                                        <option value="Medical">Medical Result</option>
                                        <option value="Clearance">Clearance</option>
                                        <option value="Resignation">Resignation Letter</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12"><input type="file" name="document_file" class="form-control form-control-sm" required></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Upload Document</button></div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
